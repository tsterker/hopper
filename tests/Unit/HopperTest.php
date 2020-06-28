<?php

namespace TSterker\Hopper\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use TSterker\Hopper\Exchange;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;
use TSterker\Hopper\Queue;
use TSterker\Hopper\Testing\TestHopper;

class HopperTest extends TestCase
{


    /**
     * Create Hopper instance that uses a channel spy.
     * 
     * The channel spy can be obtained and asserted against by getting it via $hopper->getChannel()
     *
     * @return TestHopper
     */
    protected function getTestHopper(): TestHopper
    {
        /** @var AMQPLazyConnection|MockInterface $amqp */
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $channel = Mockery::spy(AMQPChannel::class);
        $amqp->shouldReceive('channel')->andReturn($channel);

        return new TestHopper($amqp);
    }

    /** @test */
    public function it_reuses_channel()
    {
        $channel = Mockery::spy(AMQPChannel::class);
        $amqp = Mockery::spy(AMQPLazyConnection::class);

        // Expect one channel to be created
        $amqp->shouldReceive('channel')->once()->andReturn($channel);

        $hopper = new Hopper($amqp);
        $hopper->getChannel();
        $hopper->getChannel();
        $hopper->getChannel();
    }

    /** @test */
    public function it_sets_channel_prefetch_count_by_default()
    {
        $channel = Mockery::spy(AMQPChannel::class);
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $amqp->shouldReceive('channel')->once()->andReturn($channel);

        $hopper = new class ($amqp) extends Hopper
        {
            protected int $prefetchCount = 123;
        };
        $hopper->getChannel();

        $channel->shouldHaveReceived('basic_qos')->with(0, 123, false);
    }

    /** @test */
    public function it_allows_changing_channel_prefetch_count()
    {
        $channel = Mockery::spy(AMQPChannel::class);
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $amqp->shouldReceive('channel')->once()->andReturn($channel);

        $hopper = new Hopper($amqp);

        $hopper->setPrefetchCount(999);
        $channel->shouldHaveReceived('basic_qos')->with(0, 999, false);
    }

    /** @test */
    public function it_creates_queues()
    {
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $hopper = new Hopper($amqp);

        $queue = $hopper->createQueue("the-queue");

        $this->assertInstanceOf(Queue::class, $queue);
        $this->assertEquals('the-queue', $queue->getQueueName());
    }

    /** @test */
    public function it_declares_durable_lazy_queues()
    {
        $channel = Mockery::spy(AMQPChannel::class);
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $amqp->shouldReceive('channel')->once()->andReturn($channel);
        $hopper = new Hopper($amqp);

        $hopper->declareQueue(new Queue('the-queue'));

        $channel->shouldHaveReceived('queue_declare')->once()->with(
            'the-queue',
            false,  // passive?
            true,   // duable?
            false,  // exclusive?
            false,  // auto-delete?
            false,  // nowait?
            Mockery::on(function ($properties) {
                $data = $properties->getNativeData();
                $this->assertArrayHasKey('x-queue-mode', $data);
                $this->assertEquals('lazy', $data['x-queue-mode']);
                return true;
            })
        );
    }

    /** @test */
    public function it_purges_queues()
    {
        $hopper = $this->getTestHopper();
        /** @var AMQPChannel|MockInterface $channel */
        $channel = $hopper->getChannel();

        $hopper->purgeQueue(new Queue('the-queue'));
        $channel->shouldHaveReceived('queue_purge')->once()->with('the-queue');
    }

    /** @test */
    public function it_deletes_queues()
    {
        $hopper = $this->getTestHopper();
        /** @var AMQPChannel|MockInterface $channel */
        $channel = $hopper->getChannel();

        $hopper->deleteQueue(new Queue('the-queue'));
        $channel->shouldHaveReceived('queue_delete')->once()->with('the-queue');
    }

    /** @test */
    public function it_creates_exchanges()
    {
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $hopper = new Hopper($amqp);

        $exchange = $hopper->createExchange("the-exchange");

        $this->assertInstanceOf(Exchange::class, $exchange);
        $this->assertEquals('the-exchange', $exchange->getExchangeName());
    }

    /** @test */
    public function it_declares_durable_fanout_exchanges()
    {
        $channel = Mockery::spy(AMQPChannel::class);
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $amqp->shouldReceive('channel')->once()->andReturn($channel);
        $hopper = new Hopper($amqp);

        $hopper->declareExchange(new Exchange('the-exchange'));

        $channel->shouldHaveReceived('exchange_declare')->once()->with(
            'the-exchange',
            AMQPExchangeType::FANOUT,
            false,  // passive?
            true,   // duable?
            false  // auto-delete?
        );
    }

    /** @test */
    public function it_binds_a_queue_to_an_exchange()
    {
        $channel = Mockery::spy(AMQPChannel::class);
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $amqp->shouldReceive('channel')->once()->andReturn($channel);
        $hopper = new Hopper($amqp);

        $hopper->bind(
            new Exchange('the-exchange'),
            new Queue('the-queue')
        );

        $channel->shouldHaveReceived('queue_bind')->once()->with(
            'the-queue',
            'the-exchange'
        );
    }

    /** @test */
    public function it_uses_publisher_confirms_by_default()
    {
        $channel = Mockery::spy(AMQPChannel::class);
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $amqp->shouldReceive('channel')->once()->andReturn($channel);

        $hopper = new Hopper($amqp);
        /** @var AMQPChannel|MockInterface $channel */
        $hopper->getChannel();

        $channel->shouldHaveReceived('set_ack_handler')->once();
        $channel->shouldHaveReceived('set_nack_handler')->once();
        $channel->shouldHaveReceived('confirm_select')->once();
    }

    /** @test */
    public function it_registers_global_ACK_and_NACK_handlers()
    {
        $hopper = $this->getTestHopper();

        /** @var Message $msg */
        $msg = Mockery::spy(Message::class, ['getId' => 'foo']);

        $fooAck = new TestHandler;
        $fooNack = new TestHandler;

        $hopper->onPublishAck($fooAck);
        $hopper->onPublishNack($fooNack);

        // CASE: message ACK
        $hopper->fakeAck($msg);
        $this->assertTrue($fooAck->wasCalled);
        $this->assertFalse($fooNack->wasCalled);

        // CASE: message NACK
        $fooAck->wasCalled = false;
        $hopper->fakeNack($msg);
        $this->assertFalse($fooAck->wasCalled);
        $this->assertTrue($fooNack->wasCalled);
    }

    /** @test */
    public function it_registers_publish_confirm_handlers_for_individual_messages()
    {
        $hopper = $this->getTestHopper();

        /** @var Message $foo */
        $foo = Mockery::spy(Message::class, ['getId' => 'foo']);

        $hopper->onMessagePublishAck($foo, new TestHandler);
        $hopper->onMessagePublishNack($foo, new TestHandler);
        $hopper->onMessagePublishNack($foo, new TestHandler);
        $hopper->assertHasMessageAckHandler($foo, 1);
        $hopper->assertHasMessageNackHandler($foo, 2);
    }

    /** @test */
    public function it_only_calls_message_ACK_or_NACK_handler_depending_on_signal_and_then_clears_all_handlers_for_message()
    {
        $hopper = $this->getTestHopper();

        /** @var Message $foo */
        $foo = Mockery::spy(Message::class, ['getId' => 'foo']);

        // CASE: Only call ACK
        $fooAck = new TestHandler;
        $fooNack = new TestHandler;
        $hopper->onMessagePublishAck($foo, $fooAck);
        $hopper->onMessagePublishNack($foo, $fooNack);

        $hopper->fakeAck($foo);

        $this->assertTrue($fooAck->wasCalled);
        $this->assertFalse($fooNack->wasCalled);
        $hopper->assertHasNoMessageAckHandler($foo);
        $hopper->assertHasNoMessageNackHandler($foo);

        // CASE: Only call NACK
        $fooAck = new TestHandler;
        $fooNack = new TestHandler;
        $hopper->onMessagePublishAck($foo, $fooAck);
        $hopper->onMessagePublishNack($foo, $fooNack);

        $hopper->fakeNack($foo);

        $this->assertFalse($fooAck->wasCalled);
        $this->assertTrue($fooNack->wasCalled);
        $hopper->assertHasNoMessageAckHandler($foo);
        $hopper->assertHasNoMessageNackHandler($foo);
    }
    /** @test */
    public function it_leaves_message_handlers_for_other_messages_untouched()
    {
        $hopper = $this->getTestHopper();

        /** @var Message $foo */
        $foo = Mockery::spy(Message::class, ['getId' => 'foo']);
        /** @var Message $bar */
        $bar = Mockery::spy(Message::class, ['getId' => 'bar']);

        // Register handers for $bar
        $hopper->onMessagePublishAck($bar, $barAck = new TestHandler);
        $hopper->onMessagePublishNack($bar, $barNack = new TestHandler);

        // Call ACK on $foo
        $hopper->fakeAck($foo);

        // Confirm $bar was not affected
        $this->assertFalse($barAck->wasCalled);
        $this->assertFalse($barNack->wasCalled);
        $hopper->assertHasMessageAckHandler($bar);
        $hopper->assertHasMessageNackHandler($bar);
    }

    /** @test */
    public function it_can_await_pending_publisher_confirms()
    {
        /** @var AMQPLazyConnection|MockInterface $amqp */
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $hopper = new Hopper($amqp);

        $channel = Mockery::spy(AMQPChannel::class);
        $amqp->shouldReceive('channel')->once()->andReturn($channel);

        $hopper->awaitPendingPublishConfirms();

        $channel->shouldHaveReceived('wait_for_pending_acks')->once();
    }

    /** @test */
    public function it_can_send_heartbeats()
    {
        /** @var AMQPLazyConnection|MockInterface $amqp */
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $hopper = new Hopper($amqp);

        $hopper->heartBeat();

        $amqp->shouldHaveReceived('checkHeartBeat')->once();
    }

    /** @test */
    public function it_can_reconnect()
    {
        /** @var AMQPLazyConnection|MockInterface $amqp */
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $hopper = new Hopper($amqp);

        $fooChannel = Mockery::spy(AMQPChannel::class);
        $barChannel = Mockery::spy(AMQPChannel::class);
        $amqp->shouldReceive('channel')->twice()->andReturn($fooChannel, $barChannel);

        // Confirm we are currently using (and caching) fooChannel
        $this->assertSame($fooChannel, $hopper->getChannel());
        $this->assertSame($fooChannel, $hopper->getChannel());
        $this->assertSame($fooChannel, $hopper->getChannel());

        $hopper->reconnect();
        $fooChannel->shouldHaveReceived('close')->once();
        $amqp->shouldHaveReceived('reconnect')->once();

        // Confirm we created a channel after reconnect
        $this->assertSame($barChannel, $hopper->getChannel());
    }

    /** @test */
    public function it_gracefully_deals_with_failing_channel_close_during_reconnect()
    {
        /** @var AMQPLazyConnection|MockInterface $amqp */
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $hopper = new Hopper($amqp);

        $fooChannel = Mockery::spy(AMQPChannel::class);
        $barChannel = Mockery::spy(AMQPChannel::class);
        $amqp->shouldReceive('channel')->twice()->andReturn($fooChannel, $barChannel);

        $this->assertSame($fooChannel, $hopper->getChannel());

        $fooChannel->shouldReceive('close')->andThrow(new \Exception);
        $hopper->reconnect();
        $amqp->shouldHaveReceived('reconnect')->once();

        // Confirm we created a channel after reconnect
        $this->assertSame($barChannel, $hopper->getChannel());
    }

    /** @test */
    public function it_publishes_messages_and_message_batches_to_queue()
    {
        $hopper = $this->getTestHopper();
        /** @var AMQPChannel|MockInterface $channel */
        $channel = $hopper->getChannel();

        $amqpMsg = Mockery::spy(AMQPMessage::class);
        /** @var Message $msg */
        $msg = Mockery::mock(Message::class, ['getAmqpMessage' => $amqpMsg]);

        $queue = $hopper->createQueue('the-queue');
        $hopper->publish($queue, $msg);
        $channel->shouldHaveReceived('basic_publish')->with($amqpMsg, '', 'the-queue', false);
    }

    /** @test */
    public function it_publishes_message_batches_to_queue()
    {
        $hopper = $this->getTestHopper();
        /** @var AMQPChannel|MockInterface $channel */
        $channel = $hopper->getChannel();

        $amqpMsg = Mockery::spy(AMQPMessage::class);
        /** @var Message $msg */
        $msg = Mockery::mock(Message::class, ['getAmqpMessage' => $amqpMsg]);

        $queue = $hopper->createQueue('the-queue');
        $hopper->publishBatch($queue, [$msg]);
        $channel->shouldHaveReceived('batch_basic_publish')->with($amqpMsg, '', 'the-queue', false);
        $channel->shouldHaveReceived('publish_batch');
    }

    /** @test */
    public function it_publishes_messages_to_exchange()
    {
        $hopper = $this->getTestHopper();
        /** @var AMQPChannel|MockInterface $channel */
        $channel = $hopper->getChannel();

        $amqpMsg = Mockery::spy(AMQPMessage::class);
        /** @var Message $msg */
        $msg = Mockery::mock(Message::class, ['getAmqpMessage' => $amqpMsg]);

        $exchange = $hopper->createExchange('the-exchange');

        // CASE: Publish message
        $hopper->publish($exchange, $msg);
        $channel->shouldHaveReceived('basic_publish')->with($amqpMsg, 'the-exchange', '', false);
    }

    /** @test */
    public function it_publishes_message_batches_to_exchange()
    {
        $hopper = $this->getTestHopper();
        /** @var AMQPChannel|MockInterface $channel */
        $channel = $hopper->getChannel();

        $amqpMsg = Mockery::spy(AMQPMessage::class);
        /** @var Message $msg */
        $msg = Mockery::mock(Message::class, ['getAmqpMessage' => $amqpMsg]);

        $exchange = $hopper->createExchange('the-exchange');

        $hopper->publishBatch($exchange, [$msg]);
        $channel->shouldHaveReceived('batch_basic_publish')->with($amqpMsg, 'the-exchange', '', false);
        $channel->shouldHaveReceived('publish_batch');
    }

    /** @test */
    public function it_supports_queueing_messages_for_batch_publish_also_to_separate_destinations()
    {
        $hopper = $this->getTestHopper();
        /** @var AMQPChannel|MockInterface $channel */
        $channel = $hopper->getChannel();

        $amqpMsg = Mockery::spy(AMQPMessage::class);
        /** @var Message $msg */
        $msg = Mockery::mock(Message::class, ['getAmqpMessage' => $amqpMsg]);

        $fooQueue = $hopper->createQueue('foo-queue');
        $barQueue = $hopper->createQueue('bar-queue');
        $exchange = $hopper->createExchange('the-exchange');

        $hopper->addBatchMessage($fooQueue, $msg);
        $hopper->addBatchMessage($barQueue, $msg);
        $hopper->addBatchMessage($exchange, $msg);

        $channel->shouldHaveReceived('batch_basic_publish')->with($amqpMsg, '', 'foo-queue', false);
        $channel->shouldHaveReceived('batch_basic_publish')->with($amqpMsg, '', 'bar-queue', false);
        $channel->shouldHaveReceived('batch_basic_publish')->with($amqpMsg, 'the-exchange', '', false);

        // Finally flush all at once
        $hopper->flushBatchPublishes();
        $channel->shouldHaveReceived('publish_batch')->once();
    }

    /** @test */
    public function it_registers_queue_consumer_callbacks_that_receive_hoppper_message_wrapping_amqp_message()
    {
        $hopper = $this->getTestHopper();
        /** @var AMQPChannel|MockInterface $channel */
        $channel = $hopper->getChannel();

        $queue = $hopper->createQueue('the-queue');

        $handler = new TestHandler;

        $hopper->subscribe($queue, $handler);

        $channel->shouldHaveReceived('basic_consume')->once()->with(
            'the-queue',
            '',     // consumer-tag
            false,  // no-local
            false,  // no-ack
            false,  // exclusive
            false,  // no-wait
            \Mockery::on(function ($callback) use ($handler, $channel) {

                // Simulate incoming AMQPMessage that handler is called with
                $amqpMsg = new AMQPMessage();

                $this->assertFalse($handler->wasCalled);
                $callback($amqpMsg);
                $this->assertTrue($handler->wasCalled);

                // Confirm originally registered handler is called
                // - with Hopper Message wrapping received AMQPMessage
                // - with channel assigned to it
                $this->assertSame($handler->msg->getAmqpMessage(), $amqpMsg);
                $this->assertSame($handler->msg->getChannel(), $channel);

                return true;
            }),
        );
    }

    /** @test */
    public function it_consumes_messages_with_timeout()
    {
        $hopper = $this->getTestHopper();
        /** @var AMQPChannel|MockInterface $channel */
        $channel = $hopper->getChannel();

        $timeout = 11;

        // Simulate channel consuming *twice* before is_consuming returns false
        $channel->shouldReceive('is_consuming')->times(3)->andReturn(true, true, false);

        // Confirm we are thus are waiting for channel *twice* with decreasing timeout
        $channel->shouldReceive('wait')->once()->with(null, false, $timeout);
        $channel->shouldReceive('wait')->once()->with(null, false, \Mockery::on(function ($currentTimeout) use ($timeout) {
            return $currentTimeout < $timeout;
        }));

        $hopper->consume($timeout);
    }

    /** @test */
    public function it_consumes_messages_and_simply_returns_once_timeout_is_reached_while_waiting_for_messages()
    {
        $hopper = $this->getTestHopper();
        /** @var AMQPChannel|MockInterface $channel */
        $channel = $hopper->getChannel();

        // Simulate channel consuming *twice* before is_consuming returns false
        $channel->shouldReceive('is_consuming')->once()->andReturn(true);
        $channel->shouldReceive('wait')->once()->andThrow(AMQPTimeoutException::class);  // <-- RabbitMQ reaches timeout while waiting

        $hopper->consume();
    }

    /**
     * @test
     * 
     * NOTE: This test is a very contrived way to confirm the heartbeat on tick feature
     *       and it should rather done somehow by a proper integration test with actual
     *       rabbitmq running and a consumer that takes some time.
     * 
     */
    public function it_sends_heartbeats_on_tick_if_ticks_are_declared()
    {
        /** @var AMQPLazyConnection|MockInterface $amqp */
        $amqp = Mockery::spy(AMQPLazyConnection::class);
        $channel = Mockery::spy(AMQPChannel::class);
        $amqp->shouldReceive('channel')->andReturn($channel);

        $hopper = new Hopper($amqp);
        $queue = $hopper->createQueue('the-queue');

        $hopper->publish($queue, Message::make(['foo' => 'bar']));

        // Simulate channel consuming *twice* before is_consuming returns false
        declare(ticks=1) {
            $channel->shouldReceive('is_consuming')->andReturnUsing(function () {
                usleep(1);  // Force a tick
                return false;
            });
        }
        $hopper->consume(1);

        $amqp->shouldHaveReceived('checkHeartBeat')->atLeast()->times(1);
    }
}

class TestHandler
{
    public bool $wasCalled = false;
    public Message $msg;

    public function __invoke(Message $msg, \TSterker\Hopper\Hopper $hopper): void
    {
        $this->wasCalled = true;
        $this->msg = $msg;
    }
};
