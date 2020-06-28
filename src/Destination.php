<?php

namespace TSterker\Hopper;

use InvalidArgumentException;

/**
 * Encapsulates either an exchange or queue
 */
abstract class Destination
{
    /**
     *
     * Throws in case name contains "problematic" characters.
     *
     * @see https://gitlab.gleif.org/data-governance/data-governance/issues/193
     *
     * @throws InvalidArgumentException
     *
     * @param string $name
     * @return void
     */
    public static function assertSafeDestinationName(string $name)
    {
        if (0 === \Safe\preg_match('/^([A-Za-z0-9]|[-\/:_])+$/', $name)) {
            throw new InvalidArgumentException("Name [$name] contains problematic characters. Allowed are alphanumeric strings containing -/:_");
        }
    }
}
