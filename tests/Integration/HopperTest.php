<?php

namespace TSterker\Hopper\Tests\Integration;

use Closure;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PHPUnit\Framework\Assert;
use PHPUnit\TextUI\Configuration\PHPUnit;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;

class HopperTest extends TestCase
{

    /**
     * TODO: Should move ToxiProxy logic to separate class and test this independently?
     *
     * @test
     */
    public function it_simulates_rabbitmq_down()
    {
        $this->useProxy();

        $queue = $this->hopper->createQueue('test-queue');

        $this->hopper->declareQueue($queue);

        $this->simulateRabbitMqDown();

        // $this->expectException(AMQPIOException::class);
        // $this->expectExceptionMessage('stream_socket_client(): unable to connect');
        $this->expectException(AMQPConnectionClosedException::class);
        $this->expectExceptionMessage('Broken pipe or closed connection');

        $this->hopper->declareQueue($queue);
    }

    /** @test */
    public function test_declare_lazy_queue()
    {
        // Arrange
        $queue = $this->hopper->createQueue('test-queue');
        $this->assertFalse($this->warren->hasQueue('test-queue'));

        // Act
        $this->hopper->declareQueue($queue);

        // Assert
        $this->assertTrue($this->warren->hasQueue('test-queue'));
        $this->assertTrue($this->warren->getQueue('test-queue')->hasArgument('x-queue-mode', 'lazy'));
    }

    /** @test */
    public function test_declare_lazy_queue_with_reconnect()
    {
        $this->reconnectOnErrorTest();

        // Arrange
        $queue = $this->hopper->ensureQueue('test-queue');

        // Act
        $this->simulateRabbitMqDownForNextRequest();
        $this->hopper->declareQueue($queue);

        // Assert
        $this->assertRabbitMqReconnected();
        $this->assertTrue($this->warren->hasQueue('test-queue'));
    }

    /** @test */
    public function test_declare_exchange()
    {
        // Arrange
        $exchange = $this->hopper->createExchange('test-exchange');
        $this->assertFalse($this->warren->hasExchange('test-exchange'));

        // Act
        $this->hopper->declareExchange($exchange);

        // Assert
        $this->assertTrue($this->warren->hasExchange('test-exchange'));
    }

    /** @test */
    public function test_declare_exchange_with_reconnect()
    {
        $this->reconnectOnErrorTest();

        // Arrange
        $exchange = $this->hopper->createExchange('test-exchange');

        // Act
        $this->simulateRabbitMqDownForNextRequest();
        $this->hopper->declareExchange($exchange);

        // Assert
        $this->assertRabbitMqReconnected();
        $this->assertTrue($this->warren->hasExchange('test-exchange'));
    }

    /** @test */
    public function test_bind_queue_to_exchange()
    {
        // Arrange
        $queue = $this->hopper->createQueue('test-queue');
        $exchange = $this->hopper->createExchange('test-exchange');

        $this->hopper->declareQueue($queue);
        $this->hopper->declareExchange($exchange);

        $this->assertFalse($this->warren->hasBinding('test-exchange', 'test-queue'));

        // Act
        $this->hopper->bind($exchange, $queue);

        // Assert
        $this->assertTrue($this->warren->hasBinding('test-exchange', 'test-queue'));
    }

    /** @test */
    public function test_bind_queue_to_exchange_with_reconnect()
    {
        $this->reconnectOnErrorTest();

        // Arrange
        [$exchange, $queue] = $this->hopper->ensureExchangeQueueBinding('test-exchange', 'test-queue');

        // Act
        $this->simulateRabbitMqDownForNextRequest();
        $this->hopper->bind($exchange, $queue);

        // Assert
        $this->assertRabbitMqReconnected();
        $this->assertTrue($this->warren->hasBinding('test-exchange', 'test-queue'));
    }

    /** @test */
    public function test_publish_message_to_queue()
    {
        // Arrange
        $queue = $this->hopper->ensureQueue('test-queue');

        $this->assertMessageCount('test-queue', 0);

        // Publish 1st message
        $this->hopper->publish($queue, Message::make(['foo' => 'bar']));
        $this->assertMessageCount('test-queue', 1);
        $this->assertQueueHas('test-queue', ['foo' => 'bar']);

        // Publish 2nd message
        $this->hopper->publish($queue, Message::make(['fooz' => 'baz']));
        $this->assertMessageCount('test-queue', 2);
        $this->assertQueueHas('test-queue', ['foo' => 'bar']);
        $this->assertQueueHas('test-queue', ['fooz' => 'baz']);
    }

    /** @test */
    public function test_publish_message_to_queue_with_reconnect()
    {
        $this->reconnectOnErrorTest();

        // Arrange
        $queue = $this->hopper->ensureQueue('test-queue');

        // Act
        $this->simulateRabbitMqDownForNextRequest();
        $this->hopper->publish($queue, Message::make(['foo' => 'bar']));

        // Assert
        $this->assertRabbitMqReconnected();
        $this->assertMessageCount('test-queue', 1);
    }

    /** @test */
    public function test_publish_message_to_exchange()
    {
        // Arrange
        [$exchange] = $this->hopper->ensureExchangeQueueBinding('test-exchange', 'test-queue');

        $this->assertMessageCount('test-queue', 0);

        // Send 1st message
        $this->hopper->publish($exchange, Message::make(['foo' => 'bar']));
        $this->assertMessageCount('test-queue', 1);
        $this->assertQueueHas('test-queue', ['foo' => 'bar']);

        // Send 2nd message
        $this->hopper->publish($exchange, Message::make(['baz' => 'bazinga']));
        $this->assertMessageCount('test-queue', 2);
        $this->assertQueueHas('test-queue', ['foo' => 'bar']);
        $this->assertQueueHas('test-queue', ['baz' => 'bazinga']);
    }

    /** @test */
    public function test_publish_message_to_exchange_with_reconnect()
    {
        $this->reconnectOnErrorTest();

        // Arrange
        [$exchange] = $this->hopper->ensureExchangeQueueBinding('test-exchange', 'test-queue');

        // Act
        $this->simulateRabbitMqDownForNextRequest();
        $this->hopper->publish($exchange, Message::make(['foo' => 'bar']));

        // Assert
        $this->assertRabbitMqReconnected();
        $this->assertMessageCount('test-queue', 1);
    }

    /** @test */
    public function test_subscribe_to_queue_and_consume_messages()
    {
        // Arrange
        $queue = $this->hopper->ensureQueue('test-queue');

        // Act
        $handler = new TestMessageHandler;
        $this->hopper->subscribe($queue, $handler);

        $this->hopper->publish($queue, Message::make(['foo' => 'bar']));

        // Assert
        $this->assertCount(0, $handler->messages);
        $this->hopper->consume(0.1);
        $this->assertCount(1, $handler->messages);
    }

    /** @test */
    public function test_reproduce_fullcheck_scenario()
    {
        $this->assertTrue(false);
    }


    /** @test */
    public function test_subscribe_to_queue_and_consume_messages_with_reconnect()
    {
        $this->reconnectOnErrorTest();

        // Arrange
        $queue = $this->hopper->ensureQueue('test-queue');

        // Act & Assert reconnect: Subscribe
        $handler = new TestMessageHandler;


        // TODO: subscribe/basic_consume must be handled more complex!
        // essentially we would need to store all parameters and then re-run basic_consume for all stored consumers if anything fails
        // Maybe it is easier to just have the logic in the application code

        // BTW: If we get a AMQPConnectionClosedException, it seems that the channel unsets itself from the connection
        // ...this means all information stored on the channel will be gone by that point in time.
        // ...this means it might make no sense whatsoever to recover any connection without explicitly dealing with re-building the whole pub/sub topolog
        // ...this means we should just add a try catch in a while loop on the fringes of the application (i.e. in the checker/reporter command?)

        $this->simulateRabbitMqDownForNextRequest();
        $this->hopper->subscribe($queue, $handler);
        $this->assertRabbitMqReconnected();

        // TODO: Publish & subscribe on same channel might be causing issues
        // with reconnecting. For some reason, the $handler does not receive any published messages

        // Act: Publish
        $this->simulateRabbitMqDownForNextRequest();
        $this->hopper->publish($queue, Message::make(['foo' => 'bar']));
        $this->assertRabbitMqReconnected();

        // Ensure message is successfully published, before continuing
        // NOTE: does not support retry/reconnect logic (see Hopper::awaitPendingPublishConfirms for more details)
        $this->hopper->awaitPendingPublishConfirms(2);


        // Act: Consume
        $this->assertCount(0, $handler->messages);

        $this->simulateRabbitMqDownForNextRequest();
        $this->hopper->consume(0.1);

        // Assert
        $this->assertRabbitMqReconnected();
        $this->assertCount(1, $handler->messages);
    }

    /** @test */
    public function test_consumer_acknowledgements()
    {
        $queue = $this->hopper->createQueue('test-queue');
        $this->hopper->declareQueue($queue);

        // | CASE: Consume message but never ACK
        // | -------------------------------------------------------------------
        $handler = new TestMessageHandler;
        $this->hopper->subscribe($queue, $handler);
        $this->hopper->publish($queue, Message::make(['foo' => 'bar']));
        $this->hopper->consume(0.1);

        // Disconnect consumer, so un-ACKed messages will be released
        $this->hopper->reconnect();
        $messages = $this->warren->getQueueMessages('test-queue');
        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]->wasRedelivered(), 'Expecting message to be redelivered');

        // | CASE: Consume message and ACK
        // | -------------------------------------------------------------------
        $handler = new TestMessageHandler;
        $this->hopper->subscribe($queue, $handler);
        $this->hopper->consume(0.1);

        $handler->messages[0]->ack();
        $this->assertCount(0, $this->warren->getQueueMessages('test-queue'), 'Message should now be gone from queue');
    }

    /** @test */
    public function test_publisher_confirms()
    {
        $queue = $this->hopper->createQueue('test-queue');
        $this->hopper->declareQueue($queue);

        $ackHandler = new TestMessageHandler;

        // Register ACK handler
        $this->hopper->onPublishAck($ackHandler);

        $msg = Message::make(['foo' => 'bar']);

        // Publish message & wait for publisher confirm
        $this->hopper->publish($queue, $msg);
        $this->assertCount(0, $ackHandler->messages);  // <-- so far still not ACKed
        $this->hopper->awaitPendingPublishConfirms();

        // Confirm expected message was ACKed
        $this->assertCount(1, $ackHandler->messages);
        $this->assertEquals($msg, $ackHandler->messages[0]);
    }

    // TODO: Not sure if we should implement this logic, as it will not work as expected if we reconnect after connection errors
    // /** @test */
    // public function it_retains_consumer_callbacks_during_reconnect()
    // {
    //     // Arrange
    //     $consumerCallback = new ClosureSpy;
    //     $oldChannel = $this->hopper->getChannel();
    //     $this->hopper->ensureQueue('foo');

    //     // Add consumer callback
    //     $consumerTag = $oldChannel->basic_consume('foo', '', false, false, false, false, $consumerCallback);

    //     // SANITY: Channel has expected consumer callback
    //     $this->assertEquals([$consumerTag => $consumerCallback], $this->hopper->getChannel()->callbacks);

    //     // Act: Reconnect
    //     $this->hopper->reconnect();

    //     // Confirm we got a different channel
    //     $newChannel = $this->hopper->getChannel();
    //     $this->assertNotSame($oldChannel, $newChannel);

    //     // Confirm we still have the came callback
    //     $this->assertEquals(
    //         [$consumerCallback],
    //         array_values($this->hopper->getChannel()->callbacks)
    //     );
    // }
}


class TestMessageHandler
{
    /** @var Message[] */
    public array $messages = [];

    public function __invoke(Message $msg, Hopper $hopper): void
    {
        $this->messages[] = $msg;
    }
}
