<?php

namespace TSterker\Hopper;

use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;
use TSterker\Hopper\Queue;

class Subscriber
{
    protected Hopper $hopper;

    /** @var int|float How many seconds of not receiving messages before calling the idle callback. */
    protected $idleTimeout = 10;

    /** @var callable(int|float $idleTimeout): void */
    protected $idleCallback;

    /**
     * @var float Time of last message received, used to see if we hit idle timeout
     */
    protected float $lastMessageReceivedAt = 0;

    public function __construct(Hopper $hopper)
    {
        $this->hopper = $hopper;
    }

    /**
     * @param int|float $timeout
     * @return self
     */
    public function withIdleTimeout($timeout): self
    {
        $this->idleTimeout = $timeout;

        return $this;
    }

    /** @return int|float */
    public function getIdleTimeout()
    {
        return $this->idleTimeout;
    }

    /**
     * @param callable(int|float $idleTimeout): void $callback
     * @return self
     */
    public function useIdleHandler(callable $callback): self
    {
        $this->idleCallback = $callback;

        return $this;
    }

    /**
     * 
     *
     * @param Queue $queue
     * @param callable(Message, Hopper): void $callback
     * @return self
     */
    public function subscribe(Queue $queue, callable $callback): self
    {
        $this->hopper->subscribe($queue, function (Message $m, Hopper $hopper) use ($callback): void {
            $this->lastMessageReceivedAt = microtime(true);
            $callback($m, $hopper);
        });

        return $this;
    }

    /**
     * @param int|float $timeout
     * @return void
     */
    public function consume($timeout = 0)
    {
        while (true) {
            $start = microtime(true);

            // Use smaller timeout that is not 0
            $consumeTimeout = ($timeout === 0 || $this->idleTimeout === 0)
                ? max($timeout, $this->idleTimeout)
                : min($timeout, $this->idleTimeout);

            $this->hopper->consume($consumeTimeout);

            $this->handleIdling();

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
    }

    protected function handleIdling(): void
    {
        if (!isset($this->idleCallback) || !$this->isIdle()) {
            return;
        }

        ($this->idleCallback)($this->idleTimeout);
    }

    /**
     * Check whether time elapsed since last message consumed breaches idleTimeout.
     *
     * @return boolean
     */
    protected function isIdle(): bool
    {

        if (!isset($this->idleCallback)) {
            return false;
        }

        if ($this->idleTimeout === 0) {
            return false;
        }

        return ((microtime(true) - $this->lastMessageReceivedAt) > $this->idleTimeout);
    }
}
