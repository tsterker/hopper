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
    public function test_subscribe_to_queue_and_consume_messages_with_reconnect()
    {
        $this->markTestSkipped("Not implemented / Running in infinite consume loop.");

        $this->reconnectOnErrorTest();

        // Arrange
        $queue = $this->hopper->ensureQueue('test-queue');

        // Act & Assert reconnect: Subscribe
        $handler = new TestMessageHandler;

        $this->simulateRabbitMqDownForNextRequest();
        $this->hopper->subscribe($queue, $handler);
        $this->assertRabbitMqReconnected();

        // Act: Publish
        $this->hopper->publish($queue, Message::make(['foo' => 'bar']));

        // Act: Consume
        $this->assertCount(0, $handler->messages);

        $this->simulateRabbitMqDownForNextRequest();


        while (count($handler->messages) < 1) {
            usleep(200 * 1000);
            debug('loop');
            $this->hopper->consume(1);
        }

        // $this->assertCount(1, $handler->messages);

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
