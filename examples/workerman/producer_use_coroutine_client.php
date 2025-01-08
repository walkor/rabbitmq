<?php

declare(strict_types=1);

use Workerman\RabbitMQ\Client;

require_once __DIR__ . '/../../vendor/autoload.php';

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
$res = $channel->publish($message = 'Hello World By Normal Producer. ' . time(), [], '', 'hello-coroutine');

echo " [x] Sent '$message', success: $res\n";
