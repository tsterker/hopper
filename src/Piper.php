<?php

namespace TSterker\Hopper;

use TSterker\Hopper\Contracts\Transformer;
use TSterker\Hopper\Destination;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;
use TSterker\Hopper\Queue;
use TSterker\Hopper\Subscriber;

class Piper
{
    protected Hopper $hopper;

    protected Subscriber $subscriber;

    protected Message $lastIncomingMessage;

    /** @var array<string, Message> Keep track of publish messages that have not yet been confirmed */
    protected array $pendingPublishedMessages = [];

    /** @var int Number of messages to buffer before batch-publishing them. */
    protected int $messageBufferSize;

    /** @var Message[] */
    protected array $buffer = [];

    /** @var callable(int $messageCount, int|float $flushTime): void */
    protected $flushCallback;

    /** @var callable(int|float $time): void */
    protected $idleCallback;

    /**
     * @param Hopper $hopper
     * @param integer $messageBufferSize
     * @param int|float $idleTimeout
     */
    public function __construct(Hopper $hopper, int $messageBufferSize, $idleTimeout)
    {
        $this->hopper = $hopper;
        $this->messageBufferSize = $messageBufferSize;

        $this->subscriber = (new Subscriber($this->hopper))
            ->withIdleTimeout($idleTimeout)
            ->useIdleHandler(function ($timeout): void {

                if (isset($this->idleCallback)) {
                    ($this->idleCallback)($timeout);
                }

                $this->flush();
            });

        // TODO: This might up the pendingPublisherConfirmsCounter if publishes happen before transformer is added?
        $this->registerPublishAckHandler();
    }

    /**
     * @param callable(int $messageCount, int|float $flushTime): void $flushCallback
     * @return self
     */
    public function onFlush(callable $flushCallback): self
    {
        $this->flushCallback = $flushCallback;

        return $this;
    }

    /**
     * @param callable(int|float $time): void $idleCallback
     * @return self
     */
    public function onIdle(callable $idleCallback): self
    {
        $this->idleCallback = $idleCallback;

        return $this;
    }

    public function add(Queue $in, Destination $out, Transformer $transformer): self
    {

        $this->subscriber->subscribe($in, function (Message $msg) use ($transformer, $out): void {

            $this->lastIncomingMessage = $msg;

            $outMsg = $transformer->transformMessage($msg);

            if (!$outMsg) {
                // Transformer did not return a message, so we directly
                // NACK & don't requeue the incoming message
                $msg->ignore();
                return;
            }

            $this->hopper->addBatchMessage($out, $outMsg);

            $this->pendingPublishedMessages[$outMsg->getId()] = $outMsg;

            if (count($this->pendingPublishedMessages) >= $this->messageBufferSize) {
                $this->flush();
            }
        });

        return $this;
    }

    /** @param int|float $timeout */
    public function consume($timeout = 0): void
    {
        // $this->registerPublishAckHandler();

        $this->subscriber->consume($timeout);
        $this->flush();
    }

    /**
     * Batch-publish all buffered messages & wait for their confirmation, during which the
     * publish ACK (see registerPublishAckHandler) will take care of ACKing all incoming messages
     *
     * @return void
     */
    protected function flush(): void
    {

        $messagesToBeFlushed = count($this->pendingPublishedMessages);

        if ($messagesToBeFlushed === 0) {
            return;
        }

        $startFlush = microtime(true);

        // declare(ticks=1) {
        $this->hopper->flushBatchPublishes();
        $this->hopper->awaitPendingPublishConfirms();
        // }

        $flushTime = microtime(true) - $startFlush;

        // TODO: How to allow user to react to NACK?
        if (isset($this->flushCallback)) {
            ($this->flushCallback)($messagesToBeFlushed, $flushTime);
        }

        // TODO: Don't be so wasteful!?
        // In case we did not only receive publish confirms,
        // we NACK the whole batch of incoming messages.

        if (count($this->pendingPublishedMessages) > 0) {
            $this->lastIncomingMessage->nack(true);
            $this->pendingPublishedMessages = [];
        } else {
            $this->lastIncomingMessage->ack(true);
        }

        unset($this->lastIncomingMessage);
    }

    /**
     * Register publish ACK handler to keep track of pending message publishes (by
     * removing successfully published messages from $pendingPublishedMessages). This
     * can then be used in Piper::flush to infer that all messages were successfully
     * published in case the $pendingPublishedMessages is empty after Hopper::awaitPendingPublishConfirms.
     * 
     * In the case of NACK we do nothing (i.e. leave the corresponding message as in
     * $pendingPublishedMessages).
     *
     * @return void
     */
    protected function registerPublishAckHandler()
    {
        $this->hopper->onPublishAck(function (Message $outMsg, Hopper $hopper) {
            unset($this->pendingPublishedMessages[$outMsg->getId()]);
        });

        $this->hopper->onPublishNack(function (Message $outMsg, Hopper $hopper) {
            // TODO: Handle publish NACK?
            // Here we would need to NACK only the incoming message for which the corresponding out message publish failed.
            // For this we would need to associate each outMsg with an incoming message

            echo "[" . static::class . "] NACK!\n";  // Awful! But just to have *something* show up *somewhere*, out of paranoia?

        });
    }
}
