![CI](https://github.com/tsterker/hopper/workflows/CI/badge.svg)

tsterker/hopper
----

Opinionated **RabbitMQ client** (`Hopper`) intended to facilitate the implementation of **"at least once" semantics** bundled with a **RabitMQ Management Interface client** (`Warren`).

> :construction: This library (and this README) is the rushed and incoherent result out of "brainstorming" and "coding by wishful thinking" session with very specific use-cases in mind and is bound to change or even be completely reimagined in a later iteration.

Some guiding principles/thoughts were:

- Pragmatic, purpose-focused; keeping interface minimal/simple
- Facilitate "at least once" semantics, also for chains of consumers, where
  incoming messages should only be ACKed after publish of outgoing message
  was confirmed
- Make it easy to send regular heartbeats (e.g. by leveraging declare(ticks=N))
- Don't try to generalize the solution yet (e.g. currently only support for fanout exchanges), but get a feeling for a general architecture/core concepts.
- Don't leak underlying AMQP library (too much), but internally fully commit to it.
- Consider extending library to also support AMQP libraries (bunny?) or switch to it all together. Main requirement is that publisher confirms are supported.

Some opinions, which might be loosened as the package matures:
- Enforce prefetch count
- Use publisher confirms
- Only declare *durable* queues/exchanges
- Only send persistent messages (delivery_mode=persistent)
- Only use lazy queues (x-queue-mode=lazy)
- Only raw json messages
- Only declare FANOUT exchanges
- ...

# 1. Table of Contents

- [1. Table of Contents](#1-table-of-contents)
- [2. Getting Started](#2-getting-started)
- [3. Core Concepts](#3-core-concepts)
  - [3.1. Subscriber](#31-subscriber)
  - [3.3. Piper](#33-piper)
- [4. Optimization / Parameter Tuning](#4-optimization--parameter-tuning)
  - [4.1. Optimize Message Publishing](#41-optimize-message-publishing)
  - [4.2. Publisher Confirms](#42-publisher-confirms)
    - [4.2.1. ACK latency for persisted messages](#421-ack-latency-for-persisted-messages)
- [5. Troubleshooting](#5-troubleshooting)
  - [5.1. Framing error](#51-framing-error)
- [6. TODO](#6-todo)
  - [Development](#development)

# 2. Getting Started

> :warning: The library currently depends on an `AMQPStreamConnection`. The goal is to move this dependency inside `Hopper`.


Create an AMQP connection.
```php
$connection = AMQPLazyConnection::create_connection(
    [
        ['host' => 'localhost', 'port' => 5672, 'user' => 'user', 'password' => 'pass', 'vhost' => '/'],
    ],
    [
        'keepalive' => true,
        'heartbeat' => 60,
        'connection_timeout' => 5,
        'read_write_timeout' => null,
    ]
);
```

Start using hopper

```php
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;

$hopper = new Hopper($connection);

$fooExchange = $hopper->createExchange('foo');
$fooQueue = $hopper->createQueue('foo');

// Declare exchange & bind queue to it
$hopper->declareExchange($fooExchange);
$hopper->declareQueue($fooQueue);
$hopper->bind($fooExchange, $fooQueue);

// Subscribe to queue
$hopper->subscribe($fooQueue function (Message $msg, Hopper $hopper): void {
    echo "Message {$msg->getId()} received: " . json_encode($msg->getData()) . "\n";
    $msg->ack();
});

// Publish single message
$hopper->publish($fooExchange, Message::make(['foo' => 'bar']));

// Publish message batch
$hopper->publishBatch($fooExchange, [
    Message::make(['bar' => 'baz']),
    Message::make(['baz' => 'bazinga']),
]);

// Buffer messages & batch publish
$hopper->addBatchMessage($fooExchange, Message::make(['batch' => '1']));
$hopper->addBatchMessage($fooExchange, Message::make(['batch' => '1']));
$hopper->flushBatchPublishes();

// Consume messages for 5 seconds
$hopper->consume(5);
```


# 3. Core Concepts

Below a rought outline of the core concepts that govern this library and how they are intended to be leveraged for different use cases.

## 3.1. Subscriber

     * "LOW LEVEL" (e.g. not Handler classes, plain callbacks. But convenience of idle handling)
     * - Register one or more message handler callback(s) to queue(s)
     * - Register idle handler
     * - Consume

- Subscribe to multiple queues
- Register **idle handler** that should be called when no messages are received for configured **idle timeout**

```php
use TSterker\Hopper\Subscriber;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;

/** @var Hopper $hopper */

$subscriber = new Subscriber($hopper);

// Plumbing
$source = $hopper->createExchange('source');
$fooQueue = $hopper->createQueue('foo');
$barQueue = $hopper->createQueue('bar');
$hopper->declareExchange($source);
$hopper->declareQueue($fooQueue);
$hopper->declareQueue($barQueue);
$hopper->bind($source, $fooQueue);
$hopper->bind($source, $barQueue);

$subscriber
    ->subscribe($fooQueue, function (Message $msg) {
        echo "FOO Subscriber: {$msg->getId()}\n";
        $msg->ack();
    })
    ->subscribe($barQueue, function (Message $msg) {
        echo "BAR Subscriber: {$msg->getId()}\n";
        $msg->ack();
    })
    ->withIdleTimeout(1)  // Call idle handler after 1 second of not receiving messages
    ->useIdleHandler(function ($timeout) {
        echo "idle for more than $timeout seconds...\n";
    });

$subscriber->consume();
```

## 3.3. Piper

The `Piper` builds on top of `Subscribers` and supports **"at least once"** semantics in message pipelines, by ensuring that each incoming message is only `ACK`ed after an outgoing message was confirmed.

- Connect input and output queue with a `Tranformer`
- `Transformer` receives message from input queue and returns a message that should be published to output queue
- `Piper` will take care of `ACK`ing incoming messages only once outgoing messages were successfully published
- Supports message buffering & batch publish
- `TODO`: Don't mix concepts of `onFlush` and idle callbacks?

```php
use TSterker\Hopper\Piper;
use TSterker\Hopper\Hopper;

/** @var Hopper $hopper */
class ExampleTransformer implements \TSterker\Hopper\Contracts\Transformer
{
    protected string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function transformMessage(Message $msg): Message
    {
        return Message::make(['name' => $this->name]);
    }
}

// Plumbing
$source = $hopper->createExchange('source');
$fooQueue = $hopper->createQueue('foo');
$barQueue = $hopper->createQueue('bar');
$outQueue = $hopper->createQueue('out');
$hopper->declareExchange($source);
$hopper->declareQueue($fooQueue);
$hopper->declareQueue($barQueue);
$hopper->declareQueue($outQueue);
$hopper->bind($source, $fooQueue);
$hopper->bind($source, $barQueue);

$piper = new Piper(
    $hopper,
    10,  // buffer messages and then batch-publish
    2,   // "idle timeout" in seconds to flush buffer, even if not full
);

$fooX = new ExampleTransformer('foo');
$barX = new ExampleTransformer('bar');

$piper
    ->add($fooQueue, $outQueue, $fooX)
    ->add($barQueue, $outQueue, $barX)
    ->onFlush(function (int $messageCount): void {
        echo "Flushed $messageCount messages\n";
    });

$piper->consume();
```

# 4. Optimization / Parameter Tuning

This secion is a brain-dump of things to consider for potential optimizations.

## 4.1. Optimize Message Publishing

_see https://github.com/php-amqplib/php-amqplib#optimized-message-publishing_

## 4.2. Publisher Confirms

### 4.2.1. ACK latency for persisted messages

_see https://www.rabbitmq.com/confirms.html#publisher-confirms-latency_


> `basic.ack` for a persistent message routed to a durable queue will be sent after persisting the message to disk. The RabbitMQ message store **persists messages to disk in batches** after an interval (a few hundred milliseconds) to minimise the number of fsync(2) calls, or when a queue is idle.
>
> This means that **under a constant load**, latency for `basic.ack` can reach a few hundred milliseconds. To improve throughput, applications are strongly advised to **process acknowledgements asynchronously** (as a stream) or **publish batches of messages and wait for outstanding confirms**. The exact API for this varies between client libraries.

# 5. Troubleshooting

## 5.1. Framing error

```
"Framing error, unexpected byte: 4f"
```

- Seems I only ran into this when attempting to wait for pending publisher acknowledgements (`wait_for_pending_acks`).
- Seems to happen when the `read_write_timeout` is too low (e.g. 0-10) when creating a connection
- Setting `read_write_timeout` to `null` (or ommiting it) seems to prevent this error, but might come with implications if rabbitmq would stop responding?
- Currently I settled with setting it to a reasonably high `120`. If I would run into such a timeout, I'd be happy for the process to fail.

```php
$connection = AMQPLazyConnection::create_connection(
    [$host],
    [
        'keepalive' => true,
        'heartbeat' => env('RABBITMQ_HEARTBEAT', 60),
        'connection_timeout' => env('RABBITMQ_CONNECTION_TIMEOUT', 5),
        'read_write_timeout' => 0,  // <-- Setting this to 0 consistently reproduces the error
    ]
);
```

# 6. TODO
- Allow more configuration for what types of queues/exchanges to declare
- ...

## Development

**Resources:**
- Docker-compose setup: https://github.com/php-amqplib/php-amqplib/pull/643
- Connection Recovery: https://github.com/php-amqplib/php-amqplib/blob/master/demo/connection_recovery_consume.php


```sh
docker-compose up -d
# docker-compose exec php composer install
docker-compose exec php vendor/bin/phpunit
```
