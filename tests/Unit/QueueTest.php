<?php

namespace TSterker\Hopper\Tests\Unit;

use TSterker\Hopper\Queue;

class QueueTest extends TestCase
{
    /** @test */
    public function it_is_instantiated_with_queue_name()
    {
        $this->assertEquals('the-name', (new Queue('the-name'))->getQueueName());
    }


    /** @test */
    public function it_ensures_safe_names()
    {
        new Queue('queue/with-VALID:queue_name');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Name [Not valid!] contains problematic characters");

        new Queue('Not valid!');
    }
}
