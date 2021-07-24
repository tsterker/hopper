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

    public function __construct(Hopper $hopper, $doRetry = true)
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
            debug('attempt...');
            return $callback();
        } catch (AMQPIOException | AMQPConnectionClosedException | RuntimeException | ErrorException $e) {

            debug(get_class($e) . ': ' . $e->getMessage());

            if (!$this->doRetry) {
                throw $e;
            }

            // TODO: Delay?
            usleep(200 * 1000);

            debug('reconnect & re-attempt...');

            $this->hopper->reconnect();

            return $callback();
        }
    }
}
