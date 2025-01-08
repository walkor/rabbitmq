<?php

declare(strict_types=1);

namespace Workerman\RabbitMQ\Traits;

use Workerman\RabbitMQ\Client;

trait RestartMethods
{
    /** @var null|callable 重启事件回调 */
    protected $restartCallback = null;

    /**
     * 注册重启回调
     *  - 回调函数的返回值应为 Promise\PromiseInterface|null
     *  - 入参为当前client实例、replyCode、replyText
     *
     * @param callable $callback = function (Client $client, $replyCode, $replyText): Promise\PromiseInterface|null {}
     * @return Client
     */
    public function registerRestartCallback(callable $callback): Client
    {
        $this->restartCallback = $callback;
        return $this;
    }

    /**
     * 移除重启回调
     *
     * @return Client
     */
    public function unregisterRestartCallback(): Client
    {
        $this->restartCallback = null;
        return $this;
    }
}
