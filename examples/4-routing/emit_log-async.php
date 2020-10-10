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
    $argv = array_values($argv);
    $severity = isset($argv[1]) && !empty($argv[1]) ? $argv[1] : 'info';
    $data = implode(' ', array_slice($argv, 2));
    if (empty($data)) {
        $data = "Hello World!";
    }

    (new Client())->connect()->then(function (Client $client) {
        return $client->channel();
    })->then(function (Channel $channel) {
        return $channel->exchangeDeclare('direct_logs', 'direct')->then(function () use ($channel) {
            return $channel;
        });
    })->then(function (Channel $channel) use ($data, $severity) {
        echo " [x] Sending ", $severity, ':', $data, " \n";
        return $channel->publish($data, [], 'direct_logs', $severity)->then(function () use ($channel) {
            return $channel;
        });
    })->then(function (Channel $channel) use ($data, $severity) {
        echo " [x] Sent ", $severity, ':', $data, " \n";
        $client = $channel->getClient();
        return $channel->close()->then(function () use ($client) {
            return $client;
        });
    })->then(function (Client $client) {
        $client->disconnect();
    });
};

Worker::runAll();
