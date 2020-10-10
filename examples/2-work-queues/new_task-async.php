<?php

use Bunny\Channel;
use Bunny\Message;
use Workerman\Worker;
use Workerman\RabbitMQ\Client;

require '../../vendor/autoload.php';

$worker = new Worker();

$worker->onWorkerStart = function() {

    global $argv;
    unset($argv[1]);
    $data = implode(' ', array_slice($argv, 1));
    publish('task_queue', $data, ['delivery-mode' => 2], '', 'task_queue');
    return;
    (new Client())->connect()->then(function (Client $client) {
        return $client->channel();
    })->then(function (Channel $channel) {
        return $channel->queueDeclare('task_queue', false, true, false, false)->then(function () use ($channel) {
            return $channel;
        });
    })->then(function (Channel $channel) use ($data) {
        echo " [x] Sending '{$data}'\n";
        return $channel->publish(
            $data,
            [
                'delivery-mode' => 2
            ],
            '',
            'task_queue'
        )->then(function () use ($channel) {
            return $channel;
        });
    })->then(function (Channel $channel) use ($data) {
        echo " [x] Sent '{$data}'\n";
        $client = $channel->getClient();
        return $channel->close()->then(function () use ($client) {
            return $client;
        });
    })->then(function (Client $client) {
        $client->disconnect();
    });
};

function publish($queue, $body, $header, $exchange, $routing_key)
{
    (new Client())->connect()->then(function (Client $client) {
        return $client->channel();
    })->then(function (Channel $channel) use ($queue) {
        return $channel->queueDeclare($queue, false, false, false, false)->then(function () use ($channel) {
            return $channel;
        });
    })->then(function (Channel $channel) use ($body, $header, $exchange, $routing_key) {
        echo " [x] Sending 'Hello World!'\n";
        return $channel->publish($body, $header, $exchange, $routing_key)->then(function () use ($channel) {
            return $channel;
        });
    })->then(function (Channel $channel) {
        echo " [x] Sent 'Hello World!'\n";
        $client = $channel->getClient();
        return $channel->close()->then(function () use ($client) {
            return $client;
        });
    })->then(function (Client $client) {
        $client->disconnect();
    });
}

Worker::runAll();
