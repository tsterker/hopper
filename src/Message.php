<?php

namespace TSterker\Hopper;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use OutOfBoundsException;
use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;

/**
 * Decorates AMQPMessage with some convenience/Hopper-specific functionality,
 * inspired by upcoming PhpAmqpLib 3.x.
 */
class Message
{
    protected AMQPMessage $msg;

    protected ?AMQPChannel $channel = null;

    protected bool $responded = false;

    public function __construct(AMQPMessage $msg)
    {
        $this->msg = $msg;
    }

    /**
     * @param mixed[]|JsonSerializable $body
     */
    public static function make($body = []): self
    {
        return new self(new AMQPMessage(static::encodeBody($body), [
            'message_id' => static::generateId(),  // keep track of messages for publisher confirms
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]));
    }

    /**
     * @param mixed[]|JsonSerializable $body
     */
    public function clone($body): self
    {
        $msg = clone $this->msg;

        $msg->setBody(static::encodeBody($body));
        $msg->set('message_id', static::generateId());

        return new self($msg);
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        return $this->msg->get($key);
    }

    public function getAmqpMessage(): AMQPMessage
    {
        return $this->msg;
    }


    /**
     * @return mixed[]
     */
    public function getData(): array
    {
        return \Safe\json_decode($this->msg->getBody(), true);
    }

    /**
     * @return string
     * @throws InvalidArgumentException If no Hopper message id found
     */
    public function getId(): string
    {
        try {
            return $this->msg->get('message_id');
        } catch (OutOfBoundsException $e) {
            throw new InvalidArgumentException(
                "Message does not have a message_id (was not generated by Hopper?)"
            );
        }
    }

    public function getDeliveryTag(): int
    {
        return $this->msg->getDeliveryTag();
    }

    /**
     * Acknowledge one or more messages.
     *
     * @param bool $multiple If true, the delivery tag is treated as "up to and including",
     *                       so that multiple messages can be acknowledged with a single method.
     * @link https://www.rabbitmq.com/amqp-0-9-1-reference.html#basic.ack
     */
    public function ack(bool $multiple = false): void
    {
        $this->assertUnacked();
        $this->channel->basic_ack($this->getDeliveryTag(), $multiple);  // @phpstan-ignore-line
        $this->onResponse();
    }

    /**
     * Reject & requeue (default) one or more incoming message(s).
     *
     * @param bool $multiple If true, the delivery tag is treated as "up to and including",
     *                       so that multiple messages can be rejected with a single method.
     * @param bool $requeue Whether the message should be requeued
     * @link https://www.rabbitmq.com/amqp-0-9-1-reference.html#basic.nack
     */
    public function nack(bool $multiple = false, bool $requeue = true): void
    {
        $this->assertUnacked();
        $this->channel->basic_nack($this->getDeliveryTag(), $multiple, $requeue);  // @phpstan-ignore-line
        $this->onResponse();
    }

    /**
     * NACK & don't requeue one or more incoming message(s).
     *
     * @param boolean $multiple
     * @return void
     */
    public function ignore(bool $multiple = false): void
    {
        $this->nack($multiple, false);
    }

    /**
     * @param AMQPChannel $channel
     * @return $this
     * @throws \RuntimeException
     */
    public function setChannel($channel)
    {
        if (isset($this->channel)) {
            throw new LogicException('The message is already assigned to a channel');
        }
        $this->channel = $channel;

        return $this;
    }

    /**
     * @return AMQPChannel|null
     */
    public function getChannel(): ?AMQPChannel
    {
        return $this->channel;
    }

    protected function onResponse(): void
    {
        $this->responded = true;
    }

    /**
     * @throws \LogicException When response to broker was already sent.
     */
    protected function assertUnacked(): void
    {
        if (!$this->channel || $this->responded) {
            throw new \LogicException('Message was not published by hopper (has no channel set) or response was already sent');
        }
    }

    protected static function generateId(): string
    {
        return (string) Uuid::uuid4();
    }

    /**
     * @param mixed[]|JsonSerializable $body
     * @return string JSON encoded body
     */
    protected static function encodeBody($body): string
    {
        if ($body instanceof JsonSerializable) {
            $body = $body->jsonSerialize();
        }

        if (!is_array($body)) {
            throw new InvalidArgumentException(
                "Message body must be array or instance of JsonSerializable, got "  .  (is_object($body) ? get_class($body) : gettype($body))
            );
        }

        return \Safe\json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    public function __toString()
    {
        return $this->msg->getBody();
    }
}
