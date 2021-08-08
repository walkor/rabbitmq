<?php
namespace Workerman\RabbitMQ;

use Bunny\ClientStateEnum;
use Bunny\Exception\ClientException;
use Bunny\Protocol\HeartbeatFrame;
use Bunny\Protocol\MethodConnectionStartFrame;
use Bunny\Protocol\MethodConnectionTuneFrame;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use React\Promise;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use Workerman\Lib\Timer;

class Client extends \Bunny\Async\Client
{
    /**
     * Constructor.
     *
     * @param array $options see {@link AbstractClient} for available options
     * @param LoggerInterface $log if argument is passed, AMQP communication will be recorded in debug level
     */
    public function __construct(array $options = [], LoggerInterface $log = null)
    {
        $options["async"] = isset($options["async"]) ? $options["async"] : true;
        $this->eventLoop = Worker::$globalEvent;
        if (!isset($options["host"])) {
            $options["host"] = "127.0.0.1";
        }

        if (!isset($options["port"])) {
            $options["port"] = 5672;
        }

        if (!isset($options["vhost"])) {
            if (isset($options["virtual_host"])) {
                $options["vhost"] = $options["virtual_host"];
                unset($options["virtual_host"]);
            } elseif (isset($options["path"])) {
                $options["vhost"] = $options["path"];
                unset($options["path"]);
            } else {
                $options["vhost"] = "/";
            }
        }

        if (!isset($options["user"])) {
            if (isset($options["username"])) {
                $options["user"] = $options["username"];
                unset($options["username"]);
            } else {
                $options["user"] = "guest";
            }
        }

        if (!isset($options["password"])) {
            if (isset($options["pass"])) {
                $options["password"] = $options["pass"];
                unset($options["pass"]);
            } else {
                $options["password"] = "guest";
            }
        }

        if (!isset($options["timeout"])) {
            $options["timeout"] = 1;
        }

        if (!isset($options["heartbeat"])) {
            $options["heartbeat"] = 60.0;
        } elseif ($options["heartbeat"] >= 2**15) {
            throw new InvalidArgumentException("Heartbeat too high: the value is a signed int16.");
        }

        if (is_callable($options['heartbeat_callback'] ?? null)) {
            $this->options['heartbeat_callback'] = $options['heartbeat_callback'];
        }

        $this->options = $options;
        $this->log = $log;

        $this->init();
    }

    /**
     * Asynchronously sends buffered data over the wire.
     *
     * - Calls {@link eventLoops}'s addWriteStream() with client's stream.
     * - Consecutive calls will return the same instance of promise.
     *
     * @return Promise\PromiseInterface
     */
    protected function flushWriteBuffer()
    {
        if ($this->flushWriteBufferPromise) {
            return $this->flushWriteBufferPromise;

        } else {
            $deferred = new Promise\Deferred();

            $this->eventLoop->add($this->getStream(), EventInterface::EV_WRITE, function ($stream) use ($deferred) {
                try {
                    $this->write();

                    if ($this->writeBuffer->isEmpty()) {
                        $this->eventLoop->del($stream, EventInterface::EV_WRITE);
                        $this->flushWriteBufferPromise = null;
                        $deferred->resolve(true);
                    }

                } catch (\Exception $e) {
                    $this->eventLoop->del($stream, EventInterface::EV_WRITE);
                    $this->flushWriteBufferPromise = null;
                    $deferred->reject($e);
                }
            });

            return $this->flushWriteBufferPromise = $deferred->promise();
        }
    }

    /**
     * Connects to AMQP server.
     *
     * Calling connect() multiple times will result in error.
     *
     * @return Promise\PromiseInterface
     */
    public function connect()
    {
        if ($this->state !== ClientStateEnum::NOT_CONNECTED) {
            return Promise\reject(new ClientException("Client already connected/connecting."));
        }

        $this->state = ClientStateEnum::CONNECTING;
        $this->writer->appendProtocolHeader($this->writeBuffer);

        try {
            $this->eventLoop->add($this->getStream(), EventInterface::EV_READ, [$this, "onDataAvailable"]);
        } catch (\Exception $e) {
            return Promise\reject($e);
        }

        return $this->flushWriteBuffer()->then(function () {
            return $this->awaitConnectionStart();

        })->then(function (MethodConnectionStartFrame $start) {
            return $this->authResponse($start);

        })->then(function () {
            return $this->awaitConnectionTune();

        })->then(function (MethodConnectionTuneFrame $tune) {
            $this->frameMax = $tune->frameMax;
            if ($tune->channelMax > 0) {
                $this->channelMax = $tune->channelMax;
            }
            return $this->connectionTuneOk($tune->channelMax, $tune->frameMax, $this->options["heartbeat"]);

        })->then(function () {
            return $this->connectionOpen($this->options["vhost"]);

        })->then(function () {
            $this->heartbeatTimer = Timer::add($this->options["heartbeat"], [$this, "onHeartbeat"], null, false);

            $this->state = ClientStateEnum::CONNECTED;
            return $this;

        });
    }

    /**
     * Disconnects client from server.
     *
     * - Calling disconnect() if client is not connected will result in error.
     * - Calling disconnect() multiple times will result in the same promise.
     *
     * @param int $replyCode
     * @param string $replyText
     * @return Promise\PromiseInterface
     */
    public function disconnect($replyCode = 0, $replyText = "")
    {
        if ($this->state === ClientStateEnum::DISCONNECTING) {
            return $this->disconnectPromise;
        }

        if ($this->state !== ClientStateEnum::CONNECTED) {
            return Promise\reject(new ClientException("Client is not connected."));
        }

        $this->state = ClientStateEnum::DISCONNECTING;

        $promises = [];

        if ($replyCode === 0) {
            foreach ($this->channels as $channel) {
                $promises[] = $channel->close($replyCode, $replyText);
            }
        }

        if ($this->heartbeatTimer) {
            Timer::del($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        return $this->disconnectPromise = Promise\all($promises)->then(function () use ($replyCode, $replyText) {
            if (!empty($this->channels)) {
                throw new \LogicException("All channels have to be closed by now.");
            }

            return $this->connectionClose($replyCode, $replyText, 0, 0);
        })->then(function () {
            $this->eventLoop->del($this->getStream(), EventInterface::EV_READ);
            $this->closeStream();
            $this->init();
            return $this;
        });
    }


    /**
     * Callback when heartbeat timer timed out.
     */
    public function onHeartbeat()
    {
        $now = microtime(true);
        $nextHeartbeat = ($this->lastWrite ?: $now) + $this->options["heartbeat"];

        if ($now >= $nextHeartbeat) {
            $this->writer->appendFrame(new HeartbeatFrame(), $this->writeBuffer);
            $this->flushWriteBuffer()->done(function () {
                $this->heartbeatTimer = Timer::add($this->options["heartbeat"], [$this, "onHeartbeat"], null, false);
            });

            if (is_callable($this->options['heartbeat_callback'] ?? null)) {
                $this->options['heartbeat_callback']->call($this);
            }
        } else {
            $this->heartbeatTimer = Timer::add($nextHeartbeat - $now, [$this, "onHeartbeat"], null, false);
        }
    }

}
