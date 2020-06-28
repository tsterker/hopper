<?php

namespace TSterker\Hopper;

/**
 * Encapsulates either a queue
 */
class Queue extends Destination
{
    protected string $name;

    public function __construct(string $name)
    {
        static::assertSafeDestinationName($name);

        $this->name = $name;
    }

    public function getQueueName(): string
    {
        return $this->name;
    }
}
