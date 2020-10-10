<?php

use Bunny\Channel;
use Bunny\Message;
use Bunny\Protocol\MethodQueueDeclareOkFrame;
use Workerman\Worker;
use Workerman\RabbitMQ\Client;

require __DIR__ . '/../../vendor/autoload.php';

$worker = new Worker();

$worker->onWorkerStart = function() {
    (new Client())->connect()->then(function (Client $client) {
        return $client->channel();
    })->then(function (Channel $channel) {
        return $channel->exchangeDeclare('logs', 'fanout')->then(function () use ($channel) {
            return $channel->queueDeclare('', false, false, true, false);
        })->then(function (MethodQueueDeclareOkFrame $frame) use ($channel) {
            return $channel->queueBind($frame->queue, 'logs')->then(function () use ($frame) {
                return $frame;
            });
        })->then(function (MethodQueueDeclareOkFrame $frame) use ($channel) {
            echo ' [*] Waiting for logs. To exit press CTRL+C', "\n";
            $channel->consume(
                function (Message $message, Channel $channel, Client $client) {
                    echo ' [x] ', $message->content, "\n";
                },
                $frame->queue,
                '',
                false,
                true
            );
        });
    });
};

Worker::runAll();
