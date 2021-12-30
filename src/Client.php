<?php
namespace Workerman\RabbitMQ;

use Bunny\AbstractClient;
use Bunny\ClientStateEnum;
use Bunny\Exception\ClientException;
use Bunny\Protocol\HeartbeatFrame;
use Bunny\Protocol\MethodConnectionStartFrame;
use Bunny\Protocol\MethodConnectionTuneFrame;
use Psr\Log\LoggerInterface;
use React\Promise;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use Workerman\Lib\Timer;

class Client extends \Bunny\Async\Client
{
    /** @var LoggerInterface */
    protected $logger;

    /**
     * Client constructor.
     * @param array $options see {@link AbstractClient} for available options
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $options = [], LoggerInterface $logger = null)
    {
        $options['async'] = true;
        $this->logger = $logger;
        AbstractClient::__construct($options);
        $this->eventLoop = Worker::$globalEvent;
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
            $this->heartbeatTimer = Timer::add($this->options["heartbeat"], [$this, "onHeartbeat"]);

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
        else{
            foreach($this->channels as $channel){
                $this->removeChannel($channel->getChannelId());
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
            if($replyCode !== 0){
                return null;
            }
            return $this->connectionClose($replyCode, $replyText, 0, 0);
        })->then(function () use ($replyCode, $replyText){
            $this->eventLoop->del($this->getStream(), EventInterface::EV_READ);
            $this->closeStream();
            $this->init();
            if($replyCode !== 0){
                Worker::stopAll(0,"RabbitMQ client disconnected: [{$replyCode}] {$replyText}");
            }
            return $this;
        });
    }

    /**
     * Callback when heartbeat timer timed out.
     */
    public function onHeartbeat()
    {
        $this->writer->appendFrame(new HeartbeatFrame(), $this->writeBuffer);
        $this->flushWriteBuffer()->then(
            function () {
                if (is_callable(
                    isset($this->options['heartbeat_callback'])
                        ? $this->options['heartbeat_callback']
                        : null
                )) {
//                    ($this->options['heartbeat_callback'])($this);
                    $this->options['heartbeat_callback']->call($this);
                }
            },
            function (\Throwable $throwable){
                if($this->logger){
                    $this->logger->debug(
                        'OnHeartbeatFailed',
                        [
                            $throwable->getMessage(),
                            $throwable->getCode(),
                            $throwable->getFile(),
                            $throwable->getLine()
                        ]
                    );
                }
                Worker::stopAll(0,"RabbitMQ client heartbeat failed: [{$throwable->getCode()}] {$throwable->getMessage()}");
            });
    }

}
