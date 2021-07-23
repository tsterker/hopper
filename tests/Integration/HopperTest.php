<?php

namespace TSterker\Hopper\Tests\Integration;

use PhpAmqpLib\Exception\AMQPIOException;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;

class HopperTest extends TestCase
{

    /** @test */
    public function it_simulates_rabbitmq_down()
    {
        $queue = $this->hopper->createQueue('test-queue');

        $this->hopper->declareQueue($queue);

        $this->simulateRabbitMqDown();

        $this->expectException(AMQPIOException::class);
        $this->expectExceptionMessage('stream_socket_client(): unable to connect');

        $this->hopper->declareQueue($queue);

        // $exception = null;
        // try {
        //     $this->hopper->declareQueue($queue);
        // } catch (AMQPIOException $exception) {
        //     $this->assertStringContainsString('stream_socket_client(): unable to connect', $exception->getMessage());
        // }
    }

    /** @test */
    public function test_declare_lazy_queue()
    {
        $queue = $this->hopper->createQueue('test-queue');

        $this->assertFalse($this->warren->hasQueue('test-queue'));
        $this->hopper->declareQueue($queue);
        $this->assertTrue($this->warren->hasQueue('test-queue'));
        $this->assertTrue($this->warren->getQueue('test-queue')->hasArgument('x-queue-mode', 'lazy'));
    }

    /** @test */
    public function test_declare_exchange()
    {
        $exchange = $this->hopper->createExchange('test-exchange');

        $this->assertFalse($this->warren->hasExchange('test-exchange'));
        $this->hopper->declareExchange($exchange);
        $this->assertTrue($this->warren->hasExchange('test-exchange'));
    }

    /** @test */
    public function test_bind_queue_to_exchange()
    {
        $queue = $this->hopper->createQueue('test-queue');
        $exchange = $this->hopper->createExchange('test-exchange');

        $this->assertFalse($this->warren->hasBinding('test-exchange', 'test-queue'));

        $this->hopper->declareQueue($queue);
        $this->hopper->declareExchange($exchange);
        $this->hopper->bind($exchange, $queue);

        $this->assertTrue($this->warren->hasBinding('test-exchange', 'test-queue'));
    }

    /** @test */
    public function test_publish_message_to_queue()
    {
        $queue = $this->hopper->createQueue('test-queue');
        $this->hopper->declareQueue($queue);

        $this->assertCount(0, $this->warren->getQueueMessages('test-queue'));

        $this->hopper->publish($queue, Message::make(['foo' => 'bar']));
        $this->assertCount(1, $this->warren->getQueueMessages('test-queue'));

        $this->hopper->publish($queue, Message::make(['foo' => 'bar']));
        $this->assertCount(2, $this->warren->getQueueMessages('test-queue'));
    }

    /** @test */
    public function test_publish_message_to_exchange()
    {
        $queue = $this->hopper->createQueue('test-queue');
        $exchange = $this->hopper->createExchange('test-exchange');
        $this->hopper->declareQueue($queue);
        $this->hopper->declareExchange($exchange);

        $this->hopper->bind($exchange, $queue);

        $this->assertCount(0, $this->warren->getQueueMessages('test-queue'));

        $this->hopper->publish($exchange, Message::make(['foo' => 'bar']));
        $this->assertCount(1, $this->warren->getQueueMessages('test-queue'));
        $this->assertEquals('{"foo":"bar"}', $this->warren->getQueueMessages('test-queue')[0]->getBody());

        $this->hopper->publish($exchange, Message::make(['baz' => 'bazinga']));
        $this->assertCount(2, $this->warren->getQueueMessages('test-queue'));
    }

    /** @test */
    public function test_subscribe_to_queue_and_consume_messages()
    {
        $queue = $this->hopper->createQueue('test-queue');
        $this->hopper->declareQueue($queue);

        $handler = new TestMessageHandler;
        $this->hopper->subscribe($queue, $handler);

        $this->hopper->publish($queue, Message::make(['foo' => 'bar']));

        $this->assertCount(0, $handler->messages);
        $this->hopper->consume(0.1);
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
