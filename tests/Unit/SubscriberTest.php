<?php

namespace TSterker\Hopper\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Message;
use TSterker\Hopper\Subscriber;

class SubscriberTest extends TestCase
{
    /**
     * @return Hopper|\Mockery\MockInterface
     */
    protected function getHopper(): Hopper
    {
        /** @var Hopper|\Mockery\MockInterface $hopper */
        $hopper = Mockery::spy(Hopper::class);

        return $hopper;
    }

    /** @test */
    public function it_has_default_idle_timeout_that_can_be_changed()
    {
        $sub = new Subscriber($this->getHopper());

        $this->assertEquals(10, $sub->getIdleTimeout());

        $sub->withIdleTimeout(99);
        $this->assertEquals(99, $sub->getIdleTimeout());
    }

    /** @test */
    public function it_subscribes_to_queue_with_callback_that_will_receive_messages()
    {
        $hopper = $this->getHopper();
        $sub = new Subscriber($hopper);

        $queue = $hopper->createQueue('the-queue');
        $handler = new TestMessageHandler;

        $sub->subscribe($queue, $handler);

        $hopper->shouldHaveReceived('subscribe')->with($queue, Mockery::on(function ($callback) use ($hopper, $handler) {

            // Call callback to confirm the original callback is called with recevied message
            $msg = Mockery::mock(Message::class);

            $this->assertFalse($handler->wasCalled);
            $this->assertNull($handler->msg);
            $callback($msg, $hopper);
            $this->assertTrue($handler->wasCalled);
            $this->assertSame($msg, $handler->msg);

            return true;
        }));
    }

    /** @test */
    public function it_consumes_messages()
    {
        $hopper = $this->getHopper();
        $sub = new Subscriber($hopper);

        $timeout = 0.01;

        $hopper->shouldReceive('consume')->once()->with($timeout);
        $hopper->shouldReceive('consume')->atLeast()->times(1)->with(Mockery::on(function ($currentTimeout) use ($timeout) {
            return $currentTimeout < $timeout;  // Timeout is decreased in subsequent calls
        }));

        $sub->consume($timeout);
    }

    /** @test */
    public function it_consumes_messages_and_supports_idle_timeout_callbacks_while_consuming()
    {
        $hopper = $this->getHopper();
        $sub = new Subscriber($hopper);

        $timeout = 0.01;
        $idleTimeout = 0.0000001;

        $idleHandler = new TestIdleHandler;

        $sub->useIdleHandler($idleHandler);

        // Idle timeout is used if it is smaller than consume timeout
        $hopper->shouldReceive('consume')->atLeast()->once()->with($idleTimeout);

        $this->assertFalse($idleHandler->wasCalled);

        $sub
            ->withIdleTimeout($idleTimeout)
            ->consume($timeout);

        $this->assertTrue($idleHandler->wasCalled);
        // $this->assertEquals($idleTimeout, $idleHandler->timeout);
    }
}

class TestMessageHandler
{
    public bool $wasCalled = false;
    public ?Message $msg = null;

    public function __invoke(Message $msg, Hopper $hopper): void
    {
        $this->wasCalled = true;
        $this->msg = $msg;
    }
};

class TestIdleHandler
{
    public bool $wasCalled = false;

    /** @var null|int|float  */
    public $timeout = null;

    /** @param int|float $timeout */
    public function __invoke($timeout): void
    {
        $this->wasCalled = true;
        $this->timeout = $timeout;
    }
};
