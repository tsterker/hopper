<?php

namespace TSterker\Hopper;

use Closure;
use ErrorException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use RuntimeException;
use TSterker\Hopper\Hopper;

/**
 * @mixin \PhpAmqpLib\Channel\AMQPChannel
 */
class RetryableChannel
{

    protected Hopper $hopper;
    protected bool $doRetry;

    public function __construct(Hopper $hopper, bool $doRetry = true)
    {
        $this->hopper = $hopper;
        $this->doRetry = $doRetry;
    }

    /**
     * @param string $method
     * @param mixed[] $args
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        debug("RetryableChannel:__call:$method");

        return $this->withReconnect(function () use ($method,  $args) {
            return $this->hopper->getChannel()->$method(...$args);
        });
    }

    /**
     * @param Closure $callback
     * @return mixed
     */
    protected function withReconnect(Closure $callback)
    {
        try {
            debug('RetryableChannel:attempt');
            debug('RetryableChannel:attempt:callbacks:' . count($this->hopper->getChannel()->callbacks));

            $consumerCallbacks = $this->hopper->getChannel()->callbacks;
            // $connection = $this->hopper->getConnection();
            // $channel = $this->hopper->getChannel();

            return $callback();
        } catch (AMQPIOException | AMQPConnectionClosedException | RuntimeException | ErrorException $e) {

            debug('RetryableChannel:' . get_class($e) . ': ' . $e->getMessage());

            if (!$this->doRetry) {
                throw $e;
            }

            // TODO: Delay?
            usleep(200 * 1000);

            debug('RetryableChannel:reconnect & re-attempt...');

            $this->hopper->reconnect();

            $this->hopper->getChannel()->callbacks = $consumerCallbacks;
            // $this->hopper->getConnection()->channels[] = $channel;
            debug('RetryableChannel:callbacks:AFTER:' . count($this->hopper->getChannel()->callbacks));

            return $callback();
        }
    }
}
