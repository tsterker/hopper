<?php

namespace TSterker\Hopper\Tests\Integration;

use PhpAmqpLib\Connection\AMQPLazyConnection;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Warren\Warren;

class TestCase extends \PHPUnit\Framework\TestCase
{
    protected Warren $warren;
    protected Hopper $hopper;

    public function setUp(): void
    {
        parent::setUp();

        $this->hopper = $this->getHopper();
        $this->warren = $this->getWarren();
    }

    /** @before */
    public function refreshRabbitMq()
    {
        $warren = $this->getWarren();
        $warren->deleteAllQueues();
        $warren->deleteAllExchanges();
    }

    protected function getHopper()
    {
        return new Hopper(new AMQPLazyConnection(HOST, (string) PORT, USER, PASS, VHOST));
    }

    protected function getWarren()
    {
        return new Warren(HOST . ':' . API_PORT, USER, PASS);
    }
}
