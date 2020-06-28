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

    public function add(Queue $in, Destination $out, Transformer $transformer): self
    {

        $this->subscriber->subscribe($in, function (Message $msg) use ($transformer, $out): void {

            $this->lastIncomingMessage = $msg;

            $outMsg = $transformer->transformMessage($msg);

            $this->hopper->addBatchMessage($out, $outMsg);

            $this->pendingPublishedMessages[$outMsg->getId()] = $outMsg;

            // echo json_encode($msg->getData()) . " --> " . json_encode($outMsg->getData()) . "\n";
            // echo $msg->getId() . " --> " . $outMsg->getId() . "\n";

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

    protected function onIdle(): void
    {
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
     * Register publish ACK handler that sends batch ACK for last incoming message
     * once all pending published messages have been confirmed.
     *
     *
     * @return void
     */
    protected function registerPublishAckHandler()
    {
        $this->hopper->onPublishAck(function (Message $outMsg, Hopper $hopper) {
            unset($this->pendingPublishedMessages[$outMsg->getId()]);
        });

        $this->hopper->onPublishNack(function (Message $outMsg, Hopper $hopper) {
            echo "NACK!\n";
            // TODO: Handle publish NACK?
            // Here we would need to NACK only the incoming message for which the corresponding out message publish failed.
            // For this we would need to associate each outMsg with an incoming message
        });
    }
}
