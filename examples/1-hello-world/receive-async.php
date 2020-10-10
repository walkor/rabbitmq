<?php

use Bunny\Channel;
use Bunny\Message;
use Workerman\Worker;
use Workerman\RabbitMQ\Client;

require __DIR__ . '/../../vendor/autoload.php';

$worker = new Worker();

$worker->onWorkerStart = function() {
    (new Client())->connect()->then(function (Client $client) {
        return $client->channel();
    })->then(function (Channel $channel) {
        return $channel->queueDeclare('hello', false, false, false, false)->then(function () use ($channel) {
            return $channel;
        });
    })->then(function (Channel $channel) {
        echo ' [*] Waiting for messages. To exit press CTRL+C', "\n";
        $channel->consume(
            function (Message $message, Channel $channel, Client $client) {
                echo " [x] Received ", $message->content, "\n";
            },
            'hello',
            '',
            false,
            true
        );
    });
};


Worker::runAll();