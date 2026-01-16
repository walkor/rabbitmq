<?php
namespace Workerman\RabbitMQ\Clients;

use Bunny\AbstractClient;
use Bunny\ClientStateEnum;
use Bunny\Exception\ClientException;
use Bunny\Protocol\Buffer;
use Bunny\Protocol\HeartbeatFrame;
use Bunny\Protocol\MethodConnectionStartFrame;
use Bunny\Protocol\MethodConnectionTuneFrame;
use Psr\Log\LoggerInterface;
use React\Promise;
use Workerman\RabbitMQ\Traits\LoggerMethods;
use Workerman\RabbitMQ\Traits\MechanismMethods;
use Workerman\RabbitMQ\Traits\RestartMethods;
use Workerman\Worker;
use Workerman\Timer;
use Bunny\Async\Client as AsyncClient;

class EventloopClient extends AsyncClient
{
    use LoggerMethods;
    use RestartMethods;
    use MechanismMethods;

    /**
     * Client constructor.
     * @param array $options = [
     *  "host" => "127.0.0.1",
     *  "port" => 5672,
     *  "vhost" => "/",
     *  "mechanism" => "AMQPLAIN"
     *  "user" => "guest",
     *  "password" => "guest",
     *  "timeout" => 10,
     *  "restart_interval" => 0,
     *  "heartbeat" => 60,
     *  "heartbeat_callback" => function(){}
     * ] {@see AbstractClient::__construct()} and {@see \Workerman\RabbitMQ\Client::authResponse()}
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $options = [], ?LoggerInterface $logger = null)
    {
        $options['async'] = true;
        $this->setLogger($logger);
        // 注册认证机制
        static::registerMechanismHandler('PLAIN', function (string $mechanism) {
            return $this->connectionStartOk([], $mechanism, sprintf("\0%s\0%s", $this->options["user"], $this->options["password"]), "en_US");
        });
        static::registerMechanismHandler('AMQPLAIN', function (string $mechanism) {
            $responseBuffer = new Buffer();
            $this->writer->appendTable([
                "LOGIN" => $this->options["user"],
                "PASSWORD" => $this->options["password"],
            ], $responseBuffer);

            $responseBuffer->discard(4);

            return $this->connectionStartOk([], $mechanism, $responseBuffer->read($responseBuffer->getLength()), "en_US");
        });
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
    protected function flushWriteBuffer(): Promise\PromiseInterface
    {
        if ($this->flushWriteBufferPromise) {
            return $this->flushWriteBufferPromise;

        } else {
            $deferred = new Promise\Deferred();

            $streamFunction = function ($stream) use ($deferred) {
                try {
                    $this->write();

                    if ($this->writeBuffer->isEmpty()) {
                        $this->eventLoop->offWritable($stream);
                        $this->flushWriteBufferPromise = null;
                        $deferred->resolve(true);
                    }

                } catch (\Exception $e) {
                    $this->eventLoop->offWritable($stream);
                    $this->flushWriteBufferPromise = null;
                    $deferred->reject($e);
                }
            };
            $this->eventLoop->onWritable($this->getStream(), $streamFunction);
            return $this->flushWriteBufferPromise = $deferred->promise();
        }
    }

    /**
     * Override to support PLAIN mechanism
     * @param MethodConnectionStartFrame $start
     * @return bool|Promise\PromiseInterface
     */
    protected function authResponse(MethodConnectionStartFrame $start): Promise\PromiseInterface|bool
    {
        $mechanism = $this->options['mechanism'] ?? 'AMQPLAIN';
        if (!str_contains($start->mechanisms, $mechanism)) {
            throw new ClientException("Server does not support $mechanism mechanism (supported: {$start->mechanisms}).");
        }
        // 认证机制
        if ($handler = static::getMechanismHandler($mechanism)) {
            return $handler($mechanism, $start);
        }
        throw new ClientException("Client does not support $mechanism mechanism. ");
    }

    /**
     * Connects to AMQP server.
     *
     * Calling connect() multiple times will result in error.
     *
     * @return Promise\PromiseInterface
     */
    public function connect(): Promise\PromiseInterface
    {
        if ($this->state !== ClientStateEnum::NOT_CONNECTED) {
            return Promise\reject(new ClientException("Client already connected/connecting."));
        }

        $this->state = ClientStateEnum::CONNECTING;
        $this->writer->appendProtocolHeader($this->writeBuffer);

        try {
            $this->eventLoop->onReadable($this->getStream(), [$this, "onDataAvailable"]);
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
            if (isset($this->options["heartbeat"]) && $this->options["heartbeat"] > 0) {
                $this->heartbeatTimer = Timer::add($this->options["heartbeat"], [$this, "onHeartbeat"]);
            }

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
     * @return Promise\PromiseInterface|null
     */
    public function disconnect($replyCode = 0, $replyText = ""): ?Promise\PromiseInterface
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
            Timer::del((int)$this->heartbeatTimer);
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
            $this->eventLoop->offReadable($this->getStream());
            $this->closeStream();
            $this->init();
            if ($replyCode !== 0) {
                // 触发重启事件回调
                if ($this->restartCallback) {
                    return call_user_func($this->restartCallback, $this, $replyCode, $replyText);
                }
                // 默认重启流程
                else {
                    // 延迟重启
                    if (($restartInterval = $this->options['restart_interval'] ?? 0) > 0) {
                        Worker::log("RabbitMQ client will restart in $restartInterval seconds. ");

                        $timerFunction = function () use ($replyCode, $replyText, $restartInterval) {
                            Worker::stopAll(0,"RabbitMQ client disconnected: [{$replyCode}] {$replyText}");
                        };
                        $this->eventLoop->delay($restartInterval, $timerFunction);
                        return null;
                    }
                    // 立即重启
                    else {
                        Worker::stopAll(0,"RabbitMQ client disconnected: [{$replyCode}] {$replyText}");
                    }
                }
            }
            return $this;
        });
    }

    /**
     * Callback when heartbeat timer timed out.
     */
    public function onHeartbeat(): void
    {
        $this->writer->appendFrame(new HeartbeatFrame(), $this->writeBuffer);
        $this->flushWriteBuffer()->then(
            function () {
                if (is_callable(
                    $this->options['heartbeat_callback'] ?? null
                )) {
                    $this->options['heartbeat_callback']->call($this);
                }
            },
            function (\Throwable $throwable){
                $this->logger?->debug(
                    'OnHeartbeatFailed',
                    [
                        $throwable->getMessage(),
                        $throwable->getCode(),
                        $throwable->getFile(),
                        $throwable->getLine()
                    ]
                );
                Worker::stopAll(0,"RabbitMQ client heartbeat failed: [{$throwable->getCode()}] {$throwable->getMessage()}");
            });
    }

    /**
     * override run
     *
     * @param $maxSeconds
     * @return void
     */
    public function run($maxSeconds = null)
    {

    }
}
