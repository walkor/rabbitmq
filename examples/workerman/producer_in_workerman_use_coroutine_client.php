<?php

declare(strict_types=1);

use Workerman\RabbitMQ\Client;
use Workerman\Worker;

require_once __DIR__ . '/../../vendor/autoload.php';

$worker = new Worker();
$worker->eventLoop = \Workerman\Events\Revolt::class;

$worker->onWorkerStart = function() {
    $client = Client::factory([
        'host' => 'host.docker.internal',
        'port' => 5672,
        'user' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
        'heartbeat' => 60,
        'heartbeat_callback' => function () {
            echo "coroutine-producer-heartbeat\n";
        }
    ])->connect();
    $channel = $client->channel();
    $channel->queueDeclare('hello-coroutine');

    // 每5秒发一个消息
    \Workerman\Timer::add(5, function () use ($channel) {
        $channel->publish($message = 'Hello World By Workerman Env Producer. ' . time(), [], '', 'hello-coroutine');
        echo " [x] Sent '$message'\n";
    });
};
Worker::runAll();
