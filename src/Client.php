<?php

namespace Workerman\RabbitMQ;

use Workerman\RabbitMQ\Clients\CoroutineClient;
use Workerman\RabbitMQ\Clients\EventloopClient;
use Bunny\AbstractClient;
use Psr\Log\LoggerInterface;

class Client extends EventloopClient
{
    /**
     * 创建客户端
     *
     * @param array $options
     * @param LoggerInterface|null $logger
     * @param string $clientClassname
     * @return AbstractClient|CoroutineClient|EventloopClient
     */
    public static function factory(array $options = [], ?LoggerInterface $logger = null, string $clientClassname = CoroutineClient::class): CoroutineClient|EventloopClient|AbstractClient
    {
        if (!is_a($clientClassname, AbstractClient::class, true)) {
            throw new \InvalidArgumentException("$clientClassname must be a subclass of " . AbstractClient::class);
        }
        return new $clientClassname($options, $logger);
    }
}
