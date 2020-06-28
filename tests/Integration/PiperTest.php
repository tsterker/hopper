<?php

namespace TSterker\Hopper\Tests\Integration;

use TSterker\Hopper\Contracts\Transformer;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;
use TSterker\Hopper\Piper;

class PiperTest extends TestCase
{

    /** @test */
    public function test_transforming_messages_with_publish_buffer()
    {
        $sourceQueue = $this->hopper->createQueue('source');
        $destQueue = $this->hopper->createQueue('dest');
        $this->hopper->declareQueue($sourceQueue);
        $this->hopper->declareQueue($destQueue);

        $piper = new Piper(
            $this->hopper,
            3,  // buffer
            0  // idle timeout (0 means no idle timeout)
        );

        // We'll keep track of flushes
        $flushCallback = new TestFlushCallback;
        $piper->onFlush($flushCallback);

        $this->assertCount(0, $this->warren->getQueueMessages('source'));
        $this->assertCount(0, $this->warren->getQueueMessages('dest'));

        // Publish 5 messages (need 3 for buffered messages to flush)
        $this->hopper->publish($sourceQueue, Message::make(['i' => '1']));
        $this->hopper->publish($sourceQueue, Message::make(['i' => '2']));
        $this->hopper->publish($sourceQueue, Message::make(['i' => '3']));
        $this->hopper->publish($sourceQueue, Message::make(['i' => '4']));
        $this->hopper->publish($sourceQueue, Message::make(['i' => '5']));

        // So far all messages are still in source queue
        $this->assertCount(5, $this->warren->getQueueMessages('source'));
        $this->assertCount(0, $this->warren->getQueueMessages('dest'));

        // Now attach tranformer
        // - this will add a consumer to the source queue that will start pre-fetching messages
        // - but messages will not be handled/transformed yet, as we didn't start consuming
        $transformer = new TestMessageTransformer;
        $piper->add($sourceQueue, $destQueue, $transformer);

        $this->assertCount(0, $this->warren->getQueueMessages('source'));
        $this->assertCount(0, $this->warren->getQueueMessages('dest'));
        $this->assertCount(0, $transformer->messages);

        // Finally, consume messages and reconnect
        // - All messages ended up in dest
        // - transformer handled all messages
        // - we did flush the buffer 2 times (5 messages with buffer size 3; messages are always flushed after consuming) 
        $piper->consume(0.1);
        $this->hopper->reconnect();  // <-- Any un-ACKed messages would end up in source queue

        $this->assertCount(0, $this->warren->getQueueMessages('source'));
        $this->assertCount(5, $this->warren->getQueueMessages('dest'));
        $this->assertCount(5, $transformer->messages);
        $this->assertEquals(2, $flushCallback->flushCount);
    }
}

class TestMessageTransformer implements Transformer
{
    /** @var Message[] */
    public array $messages = [];

    public function transformMessage(Message $msg): Message
    {
        return $this->messages[$msg->getId()] = Message::make(['inMessage' => $msg->getId()]);
    }
}


class TestFlushCallback
{
    public int $flushCount = 0;

    /**
     * @param int $messageCount
     * @param float|int $flushTime
     * @return void
     */
    public function __invoke(int $messageCount, $flushTime): void
    {
        $this->flushCount++;
    }
}
