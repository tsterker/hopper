<?php

namespace TSterker\Hopper\Testing;

use PHPUnit\Framework\Assert;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;
use TSterker\Hopper\Queue;
use TSterker\Hopper\Signal;

class TestHopper extends Hopper
{
    /** @var array<array{queue: Queue, callback: callable}>  */
    protected array $queueSubscribers = [];

    /**
     * @param string|Message $msg Message or message ID
     * @param integer $count
     * @return void
     */
    public function assertHasMessageAckHandler($msg, int $count = null): void
    {
        $this->assertHasMessageHandler($msg, Signal::ACK(), $count);
    }

    /**
     * @param string|Message $msg Message or message ID
     * @return void
     */
    public function assertHasNoMessageAckHandler($msg): void
    {
        $this->assertHasMessageAckHandler($msg, 0);
    }

    /**
     * @param string|Message $msg Message or message ID
     * @param integer $count
     * @return void
     */
    public function assertHasMessageNackHandler($msg, int $count = null): void
    {
        $this->assertHasMessageHandler($msg, Signal::NACK(), $count);
    }

    /**
     * @param string|Message $msg Message or message ID
     * @return void
     */
    public function assertHasNoMessageNackHandler($msg): void
    {
        $this->assertHasMessageNackHandler($msg, 0);
    }

    /**
     * @param string|Message $msg Message or message ID
     * @param integer $count
     * @return void
     */
    public function assertHasMessageHandler($msg, Signal $signal, int $count = null): void
    {
        $signal = (string) $signal;
        $msgId = ($msg instanceof Message) ? $msg->getId() : $msg;
        $handlers = $this->messagePublisherConfirmHandlers[$msgId][$signal] ?? [];

        if ($count === null) {
            Assert::assertNotEmpty($handlers, "No $signal handler(s) found for message [$msgId].");
        } else {
            Assert::assertCount($count, $handlers, "Expected exactly $count $signal handler(s) for message [$msgId].");
        }
    }

    /** Pretend we are handling an ACK */
    public function fakeAck(Message $msg): void
    {
        $this->handlePublisherConfirm($msg, Signal::ACK());
    }

    /** Pretend we are handling an NACK */
    public function fakeNack(Message $msg): void
    {
        $this->handlePublisherConfirm($msg, Signal::NACK());
    }

    /**
     * Wrap original subscribe message in order to remember callbacks and
     * fake-receive messages.
     * 
     * @param Queue $queue
     * @param callable(Message, Hopper): void $callback
     * @return void
     */
    public function subscribe(Queue $queue, callable $callback): void
    {
        $this->queueSubscribers[] = [
            'queue' => $queue,
            'callback' => $callback,
        ];

        parent::subscribe($queue, $callback);
    }

    public function fakeIncomingMessage(Queue $queue, Message $msg): void
    {
        foreach ($this->queueSubscribers as $sub) {
            if ($sub['queue']->getQueueName() === $queue->getQueueName()) {
                $sub['callback']($msg, $this);
            }
        }
    }
}
