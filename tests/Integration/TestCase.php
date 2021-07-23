<?php

namespace TSterker\Hopper\Tests\Integration;

use Ihsw\Toxiproxy\Proxy;
use Ihsw\Toxiproxy\Toxiproxy;
use LogicException;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use TSterker\Hopper\Hopper;
use TSterker\Hopper\Warren\Warren;

class TestCase extends \PHPUnit\Framework\TestCase
{
    protected Warren $warren;
    protected Hopper $hopper;

    /** @var Toxiproxy */
    protected $toxi;

    /** @var Proxy */
    protected $proxy;

    public function setUp(): void
    {
        parent::setUp();

        $this->hopper = $this->getHopper();
        $this->warren = $this->getWarren();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        try {
            if (isset($this->toxi) && isset($this->proxy)) {
                $this->toxi->delete($this->proxy);
            }
        } catch (\Ihsw\Toxiproxy\Exception\NotFoundException $e) {
            // ignore
        }
    }

    /** @before */
    public function refreshRabbitMq()
    {
        $warren = $this->getWarren();
        $warren->deleteAllQueues();
        $warren->deleteAllExchanges();
    }

    protected function getHopper(string $host = HOST, string $port = PORT)
    {
        $conn = new AMQPLazyConnection(
            $host,
            $port,
            USER,
            PASS,
            VHOST
        );
        return new Hopper($conn);
    }

    protected function getWarren()
    {
        return new Warren(HOST . ':' . API_PORT, USER, PASS);
    }

    protected function useProxy(): void
    {
        if ('' === TOXI_HOST) {
            $this->markTestSkipped("Toxy Proxy not configured.");
        }

        if (isset($this->toxi)) {
            return;  // No need to configure again
        }

        $this->toxi = new Toxiproxy("http://" . TOXI_HOST . ":" . TOXI_PORT);
        $this->proxy = $this->createProxy();

        $this->hopper = $this->getHopper(
            $this->proxy->getListenIp(),
            (string) $this->proxy->getListenPort(),
        );
    }

    protected function simulateRabbitMqDown(): void
    {
        $this->useProxy();

        $this->proxy->setEnabled(false);
        $this->toxi->update($this->proxy);
    }

    protected function createProxy(string $name = 'toxi'): Proxy
    {
        if (!isset($this->toxi)) {
            throw new LogicException("Toxi Proxy not configured/running/created.");
        }

        if ($proxy = $this->toxi->get($name)) {
            $this->toxi->delete($proxy);
        }

        $proxy = $this->toxi->create(
            $name,
            HOST . ":" . PORT,  // upstream
            TOXI_HOST . ":" . TOXI_AMQP_PORT
        );

        return $proxy;
    }

    public function getRabbitMqHost(): string
    {
        if (isset($this->proxy)) {
            return $this->proxy->getListenIp();
        }

        return HOST;
    }

    public function getRabbitMqPort(): string
    {
        if (isset($this->proxy)) {
            return (string) $this->proxy->getListenPort();
        }

        return PORT;
    }
}
