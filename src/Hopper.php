<?php

namespace TSterker\Hopper;

use InvalidArgumentException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;
use TSterker\Hopper\RetryableChannel;

class Hopper
{
    protected AbstractConnection $amqp;

    protected AMQPChannel $channel;

    protected int $prefetchCount = 100;

    /**
     * Whether declared queues/exchanges should remain active when server restarts
     *
     * @var bool
     */
    protected bool $durable = true;

    /**
     * Whether Hopper should try to reconnect on connection exceptions
     * See also: https://github.com/php-amqplib/php-amqplib#connection-recovery
     *
     * @var bool
     */
    protected bool $reconnectEnabled = false;

    /** @var bool Whether to use publisher confirms */
    protected bool $publisherConfirms = true;

    /**
     * Keep track of publish ACK/NACK callbacks (publisher confirms) by message_id (set for all messages sent by Hopper)
     *
     * @example Message IDs are associated with arrays of ACK/NACK handlers
     *   [
     *     'message-id-1' => [
     *       (string) Signal::ACK() => [fn($msg, $hopper) => $hopper->ack($msg)],
     *       (string) Signal::NACK() => [fn($msg, $hopper) => $hopper->nack($msg)],
     *     ],
     *     // ...
     *   ]
     *
     * @var array<string, array<Signal|string, array<callable(Message, Hopper): void>>> $messagePublisherConfirmHandlers
     */
    protected array $messagePublisherConfirmHandlers = [];

    /**
     * Globally registered publisher confirm handers.
     * @var array<Signal|string, array<callable(Message, Hopper): void>>> $publisherConfirmHandlers
     */
    protected array $publisherConfirmHandlers = [];

    /** @var callable[] */
    protected array $beforeReconnectCallbacks = [];

    /** @var callable[] */
    protected array $afterReconnectCallbacks = [];

    /**
     * Whether to send a heartbeat in case declare(ticks=1) {...} is used.
     *
     * @see https://github.com/php-enqueue/enqueue-dev/blob/master/docs/transport/amqp_lib.md#long-running-task-and-heartbeat-and-timeouts
     * @see https://github.com/php-enqueue/enqueue-dev/pull/658/files
     *
     * @var bool
     */
    protected bool $heartbeatOnTick = true;

    public function __construct(AbstractConnection $amqp)
    {
        $this->amqp = $amqp;
    }

    public function getConnection(): AbstractConnection
    {
        return $this->amqp;
    }

    public function enableReconnectOnConnectionError(): self
    {
        $this->reconnectEnabled = true;

        return $this;
    }

    /**
     * Await any pending confirms for published messages.
     *
     * NOTE:
     * In the context of long-running jobs, there is no reason why one would wait for the confirms.
     *
     * NOTE:
     * We are not using RetryableChannel, as published messages will be lost when we create a new channel.
     * This would mean that we would never succeed to await previously sent messages, as they will be
     * no longer tracked by the new channel; i.e. the new channel will not have any "published messages"
     * it's tracking and thus no messages to actually wait for.
     *
     * Example:
     * Assume we want to ACK incoming messages only if we receive publisher confirm.
     *    If process closes before ACK is received, channel will be closed and same incoming message will be redelivered
     *    => No message lost ("at least once")
     *
     * @param int $timeout
     * @return void
     */
    public function awaitPendingPublishConfirms(int $timeout = 0): void
    {
        $this->getChannel()->wait_for_pending_acks($timeout);
    }

    public function setPrefetchCount(int $prefetchCount, bool $global = true): self
    {
        $this->prefetchCount = $prefetchCount;

        $this->getChannel()->basic_qos(
            0,                    // prefetchSize: the amount of data that can be pre-fetched in bytes (like prefetchCount but in bytes); zero means "no specific limit"
            $this->prefetchCount, // prefetchCount: the number of messages that the queue will push to this consumer before it waits for acknowledgements
            $global               // global: 'false' means the setting applies per (new) consumer in the channel (existing ones are not affected), true means the setting is per channel (the prefetch size would be shared among consumers)
        );

        return $this;
    }

    public function getPrefetchCount(): int
    {
        return $this->prefetchCount;
    }

    /**
     * Convenience method to create Exchange instance
     *
     * @param string $name
     * @return Exchange
     */
    public function createExchange(string $name): Exchange
    {
        return new Exchange($name);
    }

    /**
     * Declare durable FANOUT queue.
     *
     * TODO: Make configurable
     *
     * @param Exchange $exchange
     * @return self
     */
    public function declareExchange(Exchange $exchange): self
    {
        $this->getRetryableChannel()->exchange_declare(
            $exchange->getExchangeName(),
            AMQPExchangeType::FANOUT,
            false,                      // passive     : Whether exchange should be created if does not exists or raise an error instead
            $this->durable,
            false,                      // auto-delete : Delete exchange when all consumers have finished using it
        );

        return $this;
    }

    public function ensureExchange(string $name): Exchange
    {
        $exchange = $this->createExchange($name);

        $this->declareExchange($exchange);

        return $exchange;
    }

    /**
     * Convenience method to create Queue instance
     *
     * @param string $name
     * @return Queue
     */
    public function createQueue(string $name): Queue
    {
        return new Queue($name);
    }

    /**
     * Declare durable, lazy queue in lazy
     *
     * TODO: Make configurable
     *
     * @param Queue $queue
     * @return self
     */
    public function declareQueue(Queue $queue): self
    {
        $this->getRetryableChannel()->queue_declare(
            $queue->getQueueName(),
            false,           // passive     : Whether queue should be created if does not exists or raise an error instead
            $this->durable,
            false,           // exclusive   : Whether access should only be allowed by current connection and delete queue when that connection closes
            false,           // auto-delete : Delete queue when all consumers have finished using it
            false,           // nowait
            new AMQPTable([
                "x-queue-mode" => "lazy"
            ])
        );

        return $this;
    }

    public function ensureQueue(string $name): Queue
    {
        $queue = $this->createQueue($name);

        $this->declareQueue($queue);

        return $queue;
    }

    public function beforeReconnect(callable $callback): void
    {
        $this->beforeReconnectCallbacks[] = $callback;
    }

    public function afterReconnect(callable $callback): void
    {
        $this->afterReconnectCallbacks[] = $callback;
    }

    public function bind(Exchange $exchange, Queue $queue): void
    {
        $this->getRetryableChannel()->queue_bind($queue->getQueueName(), $exchange->getExchangeName());
    }

    /**
     * Convenience method to declare & bind a queue to an exchange and retrieve the queue/exchange instances.
     *
     * @param string $exchangeName
     * @param string $queueName
     * @return array{Exchange, Queue}
     */
    public function ensureExchangeQueueBinding(string $exchangeName, string $queueName): array
    {
        $exchange = $this->createExchange($exchangeName);
        $queue = $this->createQueue($queueName);

        $this->declareQueue($queue);
        $this->declareExchange($exchange);

        $this->bind($exchange, $queue);

        return [$exchange, $queue];
    }

    public function onPublishAck(callable $callback): void
    {
        $this->publisherConfirmHandlers[(string) Signal::ACK()][] = $callback;
    }

    public function onPublishNack(callable $callback): void
    {
        $this->publisherConfirmHandlers[(string) Signal::NACK()][] = $callback;
    }

    /**
     * @param Message $msg
     * @param callable(Message, Hopper): void $callback
     * @return void
     */
    public function onMessagePublishAck(Message $msg, callable $callback): void
    {
        $this->registerPublishConfirmHandler($msg, Signal::ACK(), $callback);
    }

    /**
     * @param Message $msg
     * @param callable(Message, Hopper): void $callback
     * @return void
     */
    public function onMessagePublishNack(Message $msg, callable $callback): void
    {
        $this->registerPublishConfirmHandler($msg, Signal::NACK(), $callback);
    }

    /**
     *
     * Heartbeat handling with ticks
     * - https://github.com/php-enqueue/enqueue-dev/blob/master/docs/transport/amqp_lib.md#long-running-task-and-heartbeat-and-timeouts
     * - https://github.com/php-enqueue/enqueue-dev/pull/658/files
     *
     * @return void
     */
    public function heartBeat(): void
    {
        $this->amqp->checkHeartBeat();
    }

    public function purgeQueue(Queue $queue): self
    {
        $this->getChannel()->queue_purge($queue->getQueueName());

        return $this;
    }

    public function deleteQueue(Queue $queue): self
    {
        $this->getChannel()->queue_delete($queue->getQueueName());

        return $this;
    }

    /**
     * @param Queue $queue
     * @param callable(Message, Hopper): void $callback
     * @return void
     */
    public function subscribe(Queue $queue, callable $callback): void
    {
        $this->getRetryableChannel()->basic_consume(
            $queue->getQueueName(),
            '',     // consumer-tag
            false,  // no-local: If set, server will not send messages to the connection that published them
            false,  // no-ack: If set, the server does not expect acknowledgements for messages
            false,  // exclusive
            false,  // no-wait
            function (AMQPMessage $msg) use ($callback) {
                $callback(
                    // TODO: Must use retry-able channel & add tests!
                    // (new Message($msg))->setChannel($this->getChannel()),
                    (new Message($msg))->setChannel($this->getChannel()),
                    $this
                );
            }
        );
    }

    /**
     * Consume messages and optionally provide timeout.
     *
     * Registers tick function that sends a heardbeat every N ticks when
     * using declare(ticks) in the subscriber callback:
     * @example
     *      declare(ticks=N) {
     *          // long-running code that is not purely I/O or something like sleep() calls
     *      }
     *
     * TODO: Handle case when there is no subscription?
     *
     * @param int|float $timeout Timeout in seconds
     * @return void
     */
    public function consume($timeout = 0)
    {
        $heartbeatOnTick = function (): void {
            $this->heartBeat();
        };

        $this->heartbeatOnTick && register_tick_function($heartbeatOnTick);

        try {
            debug('Hopper:consume:consuming:PRE:' . print_r($this->getChannel()->is_consuming(), true));
            var_dump($this->getChannel()->is_consuming());

            while ($this->getChannel()->is_consuming()) {
                debug('Hopper:consume:loop');
                $start = microtime(true);

                $this->getRetryableChannel()->wait(null, false, $timeout);

                if ($timeout <= 0) {
                    continue;
                }

                // Compute remaining timeout and continue until time is up
                $stop = microtime(true);
                $timeout -= ($stop - $start);

                if ($timeout <= 0) {
                    break;
                }
            }
        } catch (AMQPTimeoutException $e) {
            // pass
        } finally {
            if ($this->heartbeatOnTick) {
                /** @phpstan-ignore-next-line */
                unregister_tick_function($heartbeatOnTick);
            }
        }
    }

    /**
     *
     * @param Destination $destination
     * @param Message $outMsg
     *
     * @return Message
     */
    public function publish(Destination $destination, Message $outMsg): Message
    {
        return $this->amqpPublish($destination, $outMsg);
    }

    /**
     *
     * @param Destination $destination
     * @param Message[] $messages
     *
     * @return Message[]
     */
    public function publishBatch(Destination $destination, array $messages): array
    {
        foreach ($messages as $msg) {
            $this->amqpPublish($destination, $msg, true);
        }

        $this->getChannel()->publish_batch();

        return $messages;
    }

    /**
     * Add a message to be batch-published once flushBatchPublishes is called
     *
     * @param Destination $dest
     * @param Message $msg
     * @return void
     */
    public function addBatchMessage(Destination $dest, Message $msg): void
    {
        $this->amqpPublish($dest, $msg, true);
    }

    public function flushBatchPublishes(): void
    {
        $this->getChannel()->publish_batch();
    }

    protected function amqpPublish(Destination $dest, Message $msg, bool $batch = false): Message
    {
        $method = $batch ? 'batch_basic_publish' : 'basic_publish';

        if ($dest instanceof Exchange) {
            $this->getRetryableChannel()->{$method}(
                // $this->getChannel()->{$method}(
                $msg->getAmqpMessage(),
                $dest->getExchangeName(),
                '',  // routing key
                false  // mandatory?
            );
        } else if ($dest instanceof Queue) {
            $this->getRetryableChannel()->{$method}(
                // $this->getChannel()->{$method}(
                $msg->getAmqpMessage(),
                '',
                $dest->getQueueName(),
                false  // mandatory?
            );
        } else {
            throw new InvalidArgumentException("Unsupported destination " . get_class($dest));
        }

        return $msg;
    }

    public function reconnect(): void
    {
        var_dump('reconnect');
        foreach ($this->beforeReconnectCallbacks as $callback) {
            $callback();
        }

        // TODO: See commented out test HopperTest::it_retains_consumer_callbacks_during_reconnect
        // $consumerCallbacks = $this->getChannel()->callbacks;  // We will retain any consumer callbacks during reconnect

        // $channelId = $this->getChannel()->getChannelId();
        // $channels = $this->amqp->channels;
        // dump("CHANNEL COUNT BEFORE: " . count($this->amqp->channels));

        $this->closeChannel();
        $this->amqp->reconnect();

        // $this->amqp->channels = $channels;
        // $this->channel = $this->getChannel($channelId);

        // dump($this->amqp->channels);
        // dump("CHANNEL COUNT AFTER: " . count($this->amqp->channels));

        // $this->getChannel()->callbacks = $consumerCallbacks;

        foreach ($this->afterReconnectCallbacks as $callback) {
            $callback();
        }
    }

    public function getChannel($id = null): AMQPChannel
    {
        if (isset($this->channel)) {
            return $this->channel;
        }

        $this->channel = $this->channel ?? $this->amqp->channel($id);

        // Enable publisher confirms when we open the channel for the first time
        if ($this->publisherConfirms) {
            $this->enablePublisherConfirms($this->channel);
        }
        // Set prefetch count when we open the channel for the first time
        $this->setPrefetchCount($this->prefetchCount);

        return $this->channel;
    }

    public function getRetryableChannel(): RetryableChannel
    {
        return new RetryableChannel($this, $this->reconnectEnabled);
    }

    protected function registerPublishConfirmHandler(Message $msg, Signal $signal, callable $callback): void
    {

        $id = $msg->getId();

        if (!isset($this->messagePublisherConfirmHandlers[$id])) {
            $this->messagePublisherConfirmHandlers[$id] = [
                (string) Signal::ACK() => [],
                (string) Signal::NACK() => [],
            ];
        }

        $this->messagePublisherConfirmHandlers[$id][(string) $signal][] = $callback;
    }

    protected function closeChannel(): void
    {
        try {
            $this->getChannel()->close();
        } catch (Throwable $e) {
            // ignore, we are abandoning the channel anyways
        } finally {
            unset($this->channel);
        }
    }

    /**
     * Put channel into "confirm mode" (publisher confirms).
     *
     * A persistent message (delivery_mode=persistent) is confirmed when it is
     * persisted to disk or when it is consumed on every queue.
     *
     * @see https://www.rabbitmq.com/blog/2011/02/10/introducing-publisher-confirms/
     * @see https://stackoverflow.com/a/41842275/1742095
     */
    protected function enablePublisherConfirms(AMQPChannel $channel): void
    {

        // TODO: In this approach we store a callback for each message!
        //       ==> we should make it convenient to keep track of published messages
        //           and provide incoming messages which should be ACKed on publish confirm!

        $channel->set_ack_handler(function (AMQPMessage $msg) {
            $this->handlePublisherConfirm(new Message($msg), Signal::ACK());
        });
        $channel->set_nack_handler(function (AMQPMessage $msg) {
            $this->handlePublisherConfirm(new Message($msg), Signal::NACK());
        });

        $channel->confirm_select();
    }

    /**
     * Handle an ACK/NACK publish response
     *
     * @param Message $msg The published message
     * @param Signal $response Whether message publish was successful (ACK/NACK)
     *
     * @return void
     */
    protected function handlePublisherConfirm(Message $msg, Signal $response): void
    {
        $id = $msg->getId();

        // Global handlers
        $handlers = $this->publisherConfirmHandlers[(string) $response] ?? [];
        foreach ($handlers as $handler) {
            $handler($msg, $this);
        }

        // Per-message handlers
        $messageHandlers = ($this->messagePublisherConfirmHandlers[$id] ?? [])[(string) $response] ?? [];
        foreach ($messageHandlers as $handler) {
            $handler($msg, $this);
        }
        unset($this->messagePublisherConfirmHandlers[$id]);
    }
}
