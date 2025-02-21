# Rabbitmq
Asynchronous rabbitmq client for PHP based on [workerman](https://github.com/walkor/workerman).

# Install
```
composer require workerman/rabbitmq
```

# Examples

## Consumer

- receive.php
```php
<?php

declare(strict_types=1);

use Bunny\Channel;
use Bunny\Message;
use Workerman\Worker;
use Workerman\RabbitMQ\Client;

require_once __DIR__ . '/vendor/autoload.php';

$worker = new Worker();
$worker->eventLoop = \Workerman\Events\Fiber::class;

$worker->onWorkerStart = function() {
    // Create RabbitMQ Client
    $client = Client::factory([
        'host' => '127.0.0.1',
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
```
- Run command `php receive.php start`.

## Publish use on Workerman ENV

- send.php
```php
<?php

declare(strict_types=1);

use Workerman\RabbitMQ\Client;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

$worker = new Worker();
$worker->eventLoop = \Workerman\Events\Fiber::class;

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
```
- Run command `php send.php start`.

## Publish script or use on PHP-FPM

- script.php
```php
<?php

declare(strict_types=1);

use Workerman\RabbitMQ\Client;

require_once __DIR__ . '/vendor/autoload.php';

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

```
- Run command `php script.php`.

## Async Consumer

- receive.php
```php
<?php

use Bunny\Channel;
use Bunny\Message;
use Workerman\Worker;
use Workerman\RabbitMQ\Client;

require __DIR__ . '/vendor/autoload.php';

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
```
- Run command `php receive.php start`.

## Async Producer

- send.php
```php
<?php
use Bunny\Channel;
use Bunny\Message;
use Workerman\Worker;
use Workerman\RabbitMQ\Client;

require __DIR__ . '/vendor/autoload.php';

$worker = new Worker();

$worker->onWorkerStart = function() {
    (new Client())->connect()->then(function (Client $client) {
        return $client->channel();
    })->then(function (Channel $channel) {
        return $channel->queueDeclare('hello', false, false, false, false)->then(function () use ($channel) {
            return $channel;
        });
    })->then(function (Channel $channel) {
        echo " [x] Sending 'Hello World!'\n";
        return $channel->publish('Hello World!', [], '', 'hello')->then(function () use ($channel) {
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
};
Worker::runAll();
```
- Run command `php send.php start`.
