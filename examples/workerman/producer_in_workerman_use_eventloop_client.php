<?php

declare(strict_types=1);

use Bunny\Channel;
use Workerman\Worker;
use Workerman\RabbitMQ\Client;

require_once __DIR__ . '/../../vendor/autoload.php';

$worker = new Worker();
$worker->eventLoop = \Workerman\Events\Fiber::class;

$worker->onWorkerStart = function() {
    $c = null;
    (new Client([
        'host' => 'host.docker.internal',
        'port' => 5672,
        'user' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
        'heartbeat' => 60,
        'heartbeat_callback' => function () {
            echo "eventloop-producer-heartbeat\n";
        }
    ]))->connect()->then(function (Client $client) {
        return $client->channel();
    })->then(function (Channel $channel) {
        return $channel->queueDeclare('hello-eventloop')->then(function () use ($channel) {
            return $channel;
        });
    })->then(function (Channel $channel) use (&$c) {
        $c = $channel;
    });

    // 每5秒发一个消息
    \Workerman\Timer::add(5, function () use (&$c) {
        if ($c) {
            $c->publish($message = 'Hello World By Workerman Env Producer. ' . time(), [], '', 'hello-eventloop');
            echo " [x] Sent '$message'\n";
        }
    });
};
Worker::runAll();
