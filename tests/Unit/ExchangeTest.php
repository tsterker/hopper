<?php

namespace TSterker\Hopper\Tests\Unit;

use TSterker\Hopper\Exchange;

class ExchangeTest extends TestCase
{
    /** @test */
    public function it_is_instantiated_with_exchange_name()
    {
        $this->assertEquals('the-name', (new Exchange('the-name'))->getExchangeName());
    }

    /** @test */
    public function it_ensures_save_names()
    {
        new Exchange('exchange/with-VALID:exchange_name');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Name [Not valid!] contains problematic characters");

        new Exchange('Not valid!');
    }
}
