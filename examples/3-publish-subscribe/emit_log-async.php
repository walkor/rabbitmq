<?php
use Bunny\Channel;
use Bunny\Message;
use Workerman\Worker;
use Workerman\RabbitMQ\Client;

require __DIR__ . '/../../vendor/autoload.php';

$worker = new Worker();

$worker->onWorkerStart = function() {
    global $argv;
    unset($argv[1]);
    $data = implode(' ', array_slice($argv, 1));
    if (empty($data)) {
        $data = "info: Hello World!";
    }

    (new Client())->connect()->then(function (Client $client) {
        return $client->channel();
    })->then(function (Channel $channel) {
        return $channel->exchangeDeclare('logs', 'fanout')->then(function () use ($channel) {
            return $channel;
        });
    })->then(function (Channel $channel) use ($data) {
        echo " [x] Sending '{$data}'\n";
        return $channel->publish($data, [], 'logs')->then(function () use ($channel) {
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
Worker::runAll();
