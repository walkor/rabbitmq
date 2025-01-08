<?php
namespace Workerman\RabbitMQ\Clients;

use Bunny\AbstractClient;
use Bunny\Exception\ClientException;
use Bunny\Protocol\AbstractFrame;
use Bunny\Protocol\Buffer;
use Bunny\Protocol\HeartbeatFrame;
use Bunny\Protocol\MethodConnectionStartFrame;
use Composer\InstalledVersions;
use Psr\Log\LoggerInterface;
use Workerman\Events\Revolt;
use Workerman\Events\Swoole;
use Workerman\Events\Swow;
use Workerman\RabbitMQ\Traits\LoggerMethods;
use Workerman\RabbitMQ\Traits\MechanismMethods;
use Workerman\RabbitMQ\Traits\RestartMethods;
use Workerman\Timer;
use Bunny\Client as BaseSyncClient;
use Workerman\Worker;

class CoroutineClient extends BaseSyncClient
{
    use LoggerMethods;
    use RestartMethods;
    use MechanismMethods;

    /**
     * @var array
     */
    protected array $interval = [];

    /**
     * @var int|null
     */
    protected ?int $heartbeatTimer = null;

    /**
     * @var bool
     */
    protected bool $workerman = false;

    protected bool $coroutine = false;

    /**
     * Client constructor.
     *
     * @param array $options = [
     *  'interval' => [10, 100]
     * ] {@see AbstractClient::__construct()} and {@see \Workerman\RabbitMQ\Client::authResponse()}
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $options = [], LoggerInterface $logger = null)
    {
        // 安装了workerman
        if (InstalledVersions::isInstalled('workerman/workerman')) {
            // after workerman start
            if ($this->workerman = Worker::$globalEvent !== null) {
                $this->coroutine = in_array(Worker::$eventLoopClass, [
                    Revolt::class, Swow::class, Swoole::class
                ]);
            }
        }
        // 设置出让间隔
        $this->interval = $this->options['interval'] ?? [10, 100];
        // 日志
        $this->setLogger($logger);
        // 注册认证机制
        static::registerMechanismHandler('PLAIN', function (string $mechanism, MethodConnectionStartFrame $start) {
            return $this->connectionStartOk([], $mechanism, sprintf("\0%s\0%s", $this->options["user"], $this->options["password"]), "en_US");
        });
        static::registerMechanismHandler('AMQPLAIN', function (string $mechanism, MethodConnectionStartFrame $start) {
            $responseBuffer = new Buffer();
            $this->writer->appendTable([
                "LOGIN" => $this->options["user"],
                "PASSWORD" => $this->options["password"],
            ], $responseBuffer);

            $responseBuffer->discard(4);

            return $this->connectionStartOk([], $mechanism, $responseBuffer->read($responseBuffer->getLength()), "en_US");
        });
        parent::__construct($options);
    }

    /** @inheritdoc **/
    public function __destruct()
    {
        $this->coroutine = false;
        parent::__destruct();
    }

    /**
     * 协程出让
     *
     * @return void
     */
    protected function sleep(): void
    {
        if ($this->workerman and $this->coroutine) {
            list($min, $max) = $this->interval;
            Timer::sleep(rand((int)$min, (int)$max) / 1000);
        }
    }

    /** @inheritdoc  */
    public function connect()
    {
        $res = parent::connect();
        if ($this->workerman) {
            // 非阻塞
            stream_set_blocking($this->getStream(), 0);
            // 心跳
            $this->heartbeatTimer = Timer::add($this->options["heartbeat"], function () {
                $this->writer->appendFrame(new HeartbeatFrame(), $this->writeBuffer);
                $this->flushWriteBuffer();
                if (is_callable(
                    $this->options['heartbeat_callback'] ?? null
                )) {
                    $this->options['heartbeat_callback']->call($this);
                }
            });
        }
        return $res;
    }

    /**
     * @return void
     */
    public function onDataAvailable(): void
    {
        $this->read();
        while (($frame = $this->reader->consumeFrame($this->readBuffer)) !== null) {
            if ($frame->channel === 0) {
                $this->onFrameReceived($frame);

            } else {
                if (!isset($this->channels[$frame->channel])) {
                    throw new ClientException(
                        "Received frame #{$frame->type} on closed channel #{$frame->channel}."
                    );
                }

                $this->channels[$frame->channel]->onFrameReceived($frame);
            }
            $this->sleep();
        }
    }

    /** @inheritdoc  */
    public function disconnect($replyCode = 0, $replyText = "")
    {
        if ($this->heartbeatTimer) {
            Timer::del($this->heartbeatTimer);
        }
        if ($this->workerman) {
            Worker::$globalEvent->offReadable($this->getStream());
        }
        return parent::disconnect($replyCode, $replyText);
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
     * override flushWriteBuffer
     *
     * @inheritdoc
     */
    protected function flushWriteBuffer(): bool
    {
        while (!$this->writeBuffer->isEmpty()) {
            $this->write();
            $this->sleep();
        }

        return true;
    }

    /**
     * override run
     *
     * @inheritdoc
     */
    public function run($maxSeconds = null): void
    {
        $this->running = true;
        if ($this->workerman) {
            if (Worker::$eventLoopClass !== Revolt::class) {
                throw new \RuntimeException('CoroutineClient only support Revolt event loop');
            }
            // 可读事件
            Worker::$globalEvent->onReadable($this->getStream(), [$this, 'onDataAvailable']);
        }
    }
}
