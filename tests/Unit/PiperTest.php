<?php

namespace TSterker\Hopper\Tests\Unit;

use LogicException;
use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Message\AMQPMessage;
use TSterker\Hopper\Contracts\Transformer;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;
use TSterker\Hopper\Piper;
use TSterker\Hopper\Queue;
use TSterker\Hopper\Subscriber;
use TSterker\Hopper\Testing\TestHopper;

class PiperTest extends TestCase
{

    protected function getHopper(): Hopper
    {
        /** @var Hopper $hopper */
        $hopper = \Mockery::spy(Hopper::class);

        return $hopper;
    }

    protected function getTestMessage(): TestMessage
    {
        return new TestMessage(Mockery::mock(AMQPMessage::class, ['getDeliveryTag' => 123]));
    }

    /** @test */
    public function it_uses_subscriber_with_idle_timeout()
    {
        $idleTimeout = 0.1;
        $bufferSize = 3;

        $piper = new TestPiper($this->getHopper(), $bufferSize, $idleTimeout);

        $this->assertEquals($idleTimeout, $piper->getSubscriber()->getIdleTimeout());
    }

    /** @test */
    public function it_works()
    {
        /** @var AbstractConnection|MockInterface $amqp */
        $amqp = Mockery::spy(AbstractConnection::class);
        /** @var AMQPChannel|MockInterface $channel */
        $channel = Mockery::spy(AMQPChannel::class);
        $amqp->shouldReceive('channel')->andReturn($channel);

        $hopper = new TestHopper($amqp);
        $idleTimeout = 0.1;
        $bufferSize = 3;

        $piper = new TestPiper($hopper, $bufferSize, $idleTimeout);

        $inQueue = $hopper->createQueue('in-queue');
        $outQueue = $hopper->createQueue('out-queue');
        $transformer = new TestTransformer;

        $piper->add($inQueue, $outQueue, $transformer);


        $this->assertEquals(0, $transformer->callCount);

        // TODO: How to handle message transforming

        // $msg = new TestMessage(Mockery::mock(AMQPMessage::class, ['getDeliveryTag' => 123]));
        $fooMsg = $this->getTestMessage();
        $barMsg = $this->getTestMessage();
        $bazMsg = $this->getTestMessage();
        // $bazingaMsg = $this->getTestMessage();

        $fooMsg->setChannel($channel);
        $barMsg->setChannel($channel);
        $bazMsg->setChannel($channel);
        // $bazingaMsg->setChannel($channel);

        // Buffer will be flushed after 3 incoming messages
        // only last message will be ACKed
        $hopper->fakeIncomingMessage($inQueue, $fooMsg);
        $hopper->fakeIncomingMessage($inQueue, $barMsg);
        $hopper->fakeIncomingMessage($inQueue, $bazMsg);

        $this->assertEquals(3, $transformer->callCount);
        $this->assertEquals(0, $fooMsg->ackCount);
        $this->assertEquals(0, $barMsg->ackCount);


        $this->markTestSkipped("We are getting a NACK, because the hopper publish ACK handler is not called (we mock it).");
        $this->assertEquals(1, $bazMsg->nackCount);


        // $hopper->fakeIncomingMessage($inQueue, $bazMsg);

        // Buffer 2 more before flush:
        // $hopper->fakeIncomingMessage($inQueue, $msg);
        // $hopper->fakeIncomingMessage($inQueue, $msg);
        // $hopper->fakeIncomingMessage($inQueue, $msg);


        // $this->expectException(LogicException::class);
        // $this->expectExceptionMessage()
    }
}

class TestPiper extends Piper
{
    public function getSubscriber(): Subscriber
    {
        return $this->subscriber;
    }
}

class TestTransformer implements Transformer
{
    public int $callCount = 0;

    public function transformMessage(Message $inMsg): Message
    {
        $this->callCount++;

        return Message::make(['text' => 'out']);
    }
}

class TestMessage extends Message
{

    public int $ackCount = 0;
    public int $nackCount = 0;

    public function ack($multiple = false): void
    {
        $this->ackCount++;
        parent::ack($multiple);
    }

    public function nack($multiple = false): void
    {
        $this->nackCount++;
        parent::nack($multiple);
    }
}
