<?php

declare(strict_types=1);

use Bunny\Channel;
use Bunny\Message;
use Workerman\Worker;
use Workerman\RabbitMQ\Client;

require_once __DIR__ . '/../../vendor/autoload.php';

$worker = new Worker();
$worker->eventLoop = \Workerman\Events\Fiber::class;

$worker->onWorkerStart = function() {
    // Create RabbitMQ Client
    $client = Client::factory([
        'host' => 'host.docker.internal',
        'port' => 5672,
        'user' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
        'heartbeat' => 60,
        'heartbeat_callback' => function () {
            echo " [-] coroutine-consumer-heartbeat\n";
        },
        'interval' => [100, 300]
    ])->connect();
    $channel = $client->channel();
    $channel->queueDeclare('hello-coroutine');

    // Consumer
    $channel->consume(function (Message $message, Channel $channel, \Bunny\AbstractClient $client) {
        echo " [>] Received ", $message->content, "\n";
    },
        'hello-coroutine',
        '',
        false,
        true
    );
    $client->run();
    echo ' [*] Waiting for messages. To exit press CTRL+C', "\n";

    // Producer
    \Workerman\Timer::add($interval = 5 , function () use ($channel) {
        $channel->publish($message = 'Hello World By Self Timer. ' . time(), [], '', 'hello-coroutine');
        echo " [<] Sent $message\n";
    });
    echo " [!] Producer timer created, interval: $interval s.\n";

};
Worker::runAll();
