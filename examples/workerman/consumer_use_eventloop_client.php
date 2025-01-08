<?php

declare(strict_types=1);

use Bunny\Channel;
use Bunny\Message;
use Workerman\Worker;
use Workerman\RabbitMQ\Client;

require_once __DIR__ . '/../../vendor/autoload.php';

$worker = new Worker();

$worker->onWorkerStart = function () {
    // Create RabbitMQ Client by promise
    $c = null;
    (new Client([
        'host' => 'host.docker.internal',
        'port' => 5672,
        'user' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
        'heartbeat' => 60,
        'heartbeat_callback' => function () {
            echo " [-] eventloop-consumer-heartbeat\n";
        }
    ]))->connect()->then(function (Client $client) {
        return $client->channel();
    })->then(function (Channel $channel) use (&$c) {
        return $channel->queueDeclare('hello-eventloop')->then(function () use ($channel, &$c) {
            $c = $channel;
            return $channel;
        });
    })->then(function (Channel $channel) {
        echo ' [*] Waiting for messages. To exit press CTRL+C', "\n";
        // Consumer
        $channel->consume(
            function (Message $message, Channel $channel, Client $client) {
                echo " [>] Received ", $message->content, "\n";
            },
            'hello-eventloop',
            '',
            false,
            true
        );
    });

    // Producer
    \Workerman\Timer::add($interval= 5, function () use (&$c) {
        if ($c) {
            $c->publish($message = 'Hello World By Self Timer. ' . time(), [], '', 'hello-eventloop');
            echo " [<] Sent '$message'\n";
        }
    });
    echo " [!] Producer timer created, interval: $interval s.\n";
};
Worker::runAll();