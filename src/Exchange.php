<?php

namespace TSterker\Hopper;

/**
 * Encapsulates either an exchange
 */
class Exchange extends Destination
{
    protected string $name;

    public function __construct(string $name)
    {
        static::assertSafeDestinationName($name);

        $this->name = $name;
    }

    public function getExchangeName(): string
    {
        return $this->name;
    }
}
