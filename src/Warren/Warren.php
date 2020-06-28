<?php

namespace TSterker\Hopper\Warren;

use GuzzleHttp\Exception\ClientException;
use Markup\RabbitMq\ApiFactory;
use Markup\RabbitMq\ManagementApi\Api\Queue as QueueApi;
use Markup\RabbitMq\ManagementApi\Client;
use TSterker\Hopper\Exceptions\NotFoundException;
use TSterker\Hopper\Warren\Exchange;

/**
 * 
 * @see https://en.wikipedia.org/wiki/Cuniculture
 */
class Warren
{

    protected ApiFactory $api;

    protected string $vhost = '/';


    public function __construct(string $baseUrl = 'http://localhost:15672', string $user = 'user', string $password = 'pass')
    {
        $client = new Client($baseUrl, $user, $password);
        $this->api = new ApiFactory($client);
    }

    /**
     * @return Queue
     * @throws NotFoundException
     */
    public function getQueue(string $name): Queue
    {
        return new Queue($this->queueRequest('get', $name));
    }

    /**
     * @return Exchange
     * @throws NotFoundException
     */
    public function getExchange(string $name): Exchange
    {
        return new Exchange($this->exchangeRequest('get', $name));
    }

    /**
     * @return Queue[]
     */
    public function getQueues(): array
    {
        return array_map(fn ($q) => new Queue($q), $this->api->queues()->all($this->vhost));
    }

    /**
     * @return Exchange[]
     */
    public function getExchanges(): array
    {
        return array_map(fn ($q) => new Exchange($q), $this->api->exchanges()->all($this->vhost));
    }

    /**
     * @param string $exchange
     * @param string $queue
     * @return Binding[]
     */
    public function getExchangeQueueBindings(string $exchange, string $queue): array
    {
        return array_map(fn ($q) => new Binding($q), $this->bindingRequest('binding', $exchange, $queue));
    }

    /**
     * @return Message[]
     * @throws NotFoundException
     */
    public function getQueueMessages(string $name, int $count = 999): array
    {
        return array_map(fn ($q) => new Message($q), $this->queueRequest('retrieveMessages', $name, $count));
    }

    /**
     * @return bool
     */
    public function hasQueue(string $name): bool
    {
        try {
            $this->getQueue($name);
        } catch (NotFoundException $e) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function hasExchange(string $name): bool
    {
        try {
            $this->getExchange($name);
        } catch (NotFoundException $e) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function hasBinding(string $exchange, string $queue): bool
    {
        return 0 !== count($this->getExchangeQueueBindings($exchange, $queue));
    }

    public function deleteQueue(string $name): void
    {
        $this->queueRequest('delete', $name);
    }

    public function deleteExchange(string $name): void
    {
        $this->exchangeRequest('delete', $name);
    }

    public function deleteAllQueues(): void
    {
        foreach ($this->getQueues() as $queue) {
            $this->deleteQueue($queue->getName());
        }
    }

    public function deleteAllExchanges(): void
    {
        foreach ($this->getExchanges() as $exchange) {
            $name = $exchange->getName();
            if ($name === '') {
                continue;
            }

            try {
                $this->deleteExchange($exchange->getName());
            } catch (ClientException $e) {
                if ($e->getCode() === 401) {
                    // Some rabbitmq default queues are not allowed to be deleted
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * @param string $method
     * @param string $name
     * @param mixed[] ...$args
     * @return mixed[]
     */
    public function queueRequest(string $method, string $name, ...$args): array
    {
        try {
            $result = $this->api->queues()->{$method}($this->vhost, $name, ...$args);
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                throw new NotFoundException("Queue [$name] does not exist.");
            }
            throw $e;
        }

        return is_null($result) ? [] : $result;
    }

    /**
     * @param string $method
     * @param string $name
     * @param mixed[] ...$args
     * @return mixed[]
     */
    public function exchangeRequest(string $method, string $name, ...$args): array
    {
        try {
            $result = $this->api->exchanges()->{$method}($this->vhost, $name, ...$args);
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                throw new NotFoundException("Exchange [$name] does not exist.");
            }
            throw $e;
        }
        return is_null($result) ? [] : $result;
    }

    /**
     * @param string $method
     * @param string $exchange
     * @param string $queue
     * @param mixed[] ...$args
     * @return mixed[]
     */
    public function bindingRequest(string $method, string $exchange, string $queue, ...$args): array
    {
        try {
            return $this->api->bindings()->{$method}($this->vhost, $exchange, $queue, ...$args);
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                throw new NotFoundException("No binding exists between exchange [$exchange] and queue [$queue].");
            }
            throw $e;
        }
    }
}
