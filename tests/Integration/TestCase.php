<?php

namespace TSterker\Hopper\Tests\Integration;

use Ihsw\Toxiproxy\Proxy;
use Ihsw\Toxiproxy\Toxiproxy;
use LogicException;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\Assert;
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

    /**
     * Keep track of reconnect attempts and provide assertions.
     *
     * @var ClosureSpy
     */
    protected ClosureSpy $beforeReconnectCallback;

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

    protected function reconnectOnErrorTest()
    {
        $this->useProxy();

        $this->beforeReconnectCallback = new ClosureSpy;
        $this->hopper->beforeReconnect($this->beforeReconnectCallback);
        $this->hopper->enableReconnectOnConnectionError();
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

        unset($this->hopper);
        $this->hopper = $this->getHopper(
            $this->proxy->getListenIp(),
            (string) $this->proxy->getListenPort(),
        );
    }

    protected function simulateRabbitMqDown(): void
    {
        $this->ensureProxyUsed();

        $this->proxy->setEnabled(false);
        $this->toxi->update($this->proxy);
    }

    protected function simulateRabbitMqUp(): void
    {
        $this->ensureProxyUsed();

        $this->proxy->setEnabled(true);
        $this->toxi->update($this->proxy);
    }


    protected function simulateRabbitMqDownForNextRequest(): void
    {
        $this->simulateRabbitMqDown();

        $this->hopper->beforeReconnect(function () {
            $this->simulateRabbitMqUp();
        });
    }

    protected function assertRabbitMqReconnected()
    {
        $this->ensureProxyUsed();

        if (!isset($this->beforeReconnectCallback)) {
            throw new LogicException("No reconnect callback registered. Do you need to first call self::reconnectOnErrorTest?");
        }

        $this->beforeReconnectCallback->assertCalled();
        $this->beforeReconnectCallback->reset();
    }

    protected function ensureProxyUsed(): void
    {
        if (!isset($this->toxi)) {
            throw new LogicException("Toxi Proxy not configured/running/created.");
        }
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

    protected function assertMessageCount(string $queueName, int $count)
    {
        $this->assertCount($count, $this->warren->getQueueMessages($queueName));
    }

    /**
     * @param string $queueName
     * @param mixed[] $messageBody
     * @return void
     */
    protected function assertQueueHas(string $queueName, array $messageBody): void
    {
        $serializedMessageBody = json_encode($messageBody);

        foreach ($this->warren->getQueueMessages($queueName) as $msg) {
            if ($serializedMessageBody == $msg->getBody()) {
                return;
            }
        }

        $this->fail("Queue [$queueName] does not have message: $serializedMessageBody");
    }
}
class ClosureSpy
{
    protected int $callCount = 0;

    public function __invoke(): void
    {
        $this->callCount++;
    }

    public function assertCalled()
    {
        Assert::assertGreaterThanOrEqual(1, $this->callCount, "Expected callback to be called at least once.");
    }

    public function reset()
    {
        $this->callCount = 0;
    }

    public function assertCalledTimes(int $count)
    {
        Assert::assertEquals($count, $this->callCount, "Expected callback to be called exactly $count times, but was called {$this->callCount}.");
    }
}
