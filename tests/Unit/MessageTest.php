<?php

namespace TSterker\Hopper\Tests\Unit;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use TSterker\Hopper\Message;

class MessageTest extends TestCase
{

    /** @test */
    public function it_is_instantiated_with_AMQPMessage_and_sets_message_id_and_delivery_mode_persistent()
    {
        $amqpMsg = new AMQPMessage('', ['message_id' => 'foo']);
        $msg = new Message($amqpMsg);

        $this->assertSame($amqpMsg, $msg->getAmqpMessage());
    }

    /**
     * @test
     * @dataProvider messageData
     * @param mixed[]|JsonSerializable $data
     */
    public function it_can_be_created_from_array_or_JsonSerializable_and_implicitly_sets_message_id_and_delivery_mode_persistent($data)
    {
        $msg = Message::make($data);

        $this->assertSame(['foo' => 'bar'], $msg->getData());
        $this->assertNotEmpty($msg->getId());
        $this->assertEquals(AMQPMessage::DELIVERY_MODE_PERSISTENT, $msg->get('delivery_mode'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Message body must be array or instance of JsonSerializable");
        $msg = Message::make('not an array or JsonSerializable');  // @phpstan-ignore-line
    }

    /** @test */
    public function test_tostring_results_in_raw_json_encoded_body()
    {
        $msg = Message::make(['foo' => 'bar']);

        $this->assertEquals('{"foo":"bar"}', (string)$msg);
    }

    /** @test */
    public function it_can_be_cloned_with_new_body_and_will_get_a_new_message_id()
    {
        $msg = Message::make(['foo' => 'bar']);

        $clone = $msg->clone(['bar' => 'baz']);

        $this->assertNotSame($msg, $clone);
        $this->assertSame(['foo' => 'bar'], $msg->getData());
        $this->assertSame(['bar' => 'baz'], $clone->getData());

        $this->assertNotNull($clone->getId());
        $this->assertNotEquals($msg->getId(), $clone->getId());
    }

    /** @test */
    public function it_gets_message_id_and_throws_if_none_is_present()
    {
        $msgWithId = new Message(new AMQPMessage('', ['message_id' => 'foo']));
        $msgWithoutId = new Message(new AMQPMessage(''));

        // CASE: Message with ID
        $this->assertEquals('foo', $msgWithId->getId());

        // CASE: Message without ID
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message does not have a message_id');
        $msgWithoutId->getId();
    }

    /** @test */
    public function it_can_have_a_channel_assigned()
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $msg = Message::make([]);

        $this->assertNull($msg->getChannel());

        $msg->setChannel($channel);
        $this->assertSame($channel, $msg->getChannel());
    }
    /** @test */
    public function it_throws_if_attempting_to_assign_a_channel_if_one_was_already_set()
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $msg = Message::make([]);

        $msg->setChannel($channel);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The message is already assigned to a channel");

        $msg->setChannel($channel);
    }

    /** @test */
    public function it_can_be_ACKed_if_channel_is_set()
    {
        $amqpMsg = Mockery::mock(AMQPMessage::class, ['getDeliveryTag' => 123]);
        $channel = Mockery::spy(AMQPChannel::class);
        $msg = new Message($amqpMsg);

        $msg->setChannel($channel);
        $msg->ack();
        $channel->shouldHaveReceived('basic_ack')->with(123, false);
    }

    /** @test */
    public function it_can_be_NACKed_and_requeued_if_channel_is_set()
    {
        $amqpMsg = Mockery::mock(AMQPMessage::class, ['getDeliveryTag' => 123]);
        $channel = Mockery::spy(AMQPChannel::class);
        $msg = new Message($amqpMsg);

        $msg->setChannel($channel);
        $msg->nack();
        $channel->shouldHaveReceived('basic_nack')->with(
            123,
            false,  // multiple
            true   // requeue
        );
    }

    /** @test */
    public function it_can_ACK_multiple_if_channel_is_set()
    {
        $amqpMsg = Mockery::mock(AMQPMessage::class, ['getDeliveryTag' => 123]);
        $channel = Mockery::spy(AMQPChannel::class);

        $msg = new Message($amqpMsg);
        $msg->setChannel($channel);
        $msg->ack(true);  // <-- multipe=true

        $channel->shouldHaveReceived('basic_ack')->with(
            123,
            true  // multiple
        );
    }

    /** @test */
    public function it_can_NACK_and_requeue_multiple_if_channel_is_set()
    {
        $amqpMsg = Mockery::mock(AMQPMessage::class, ['getDeliveryTag' => 123]);
        $channel = Mockery::spy(AMQPChannel::class);

        $msg = new Message($amqpMsg);
        $msg->setChannel($channel);
        $msg->nack(true);  // <-- multipe=true

        $channel->shouldHaveReceived('basic_nack')->with(
            123,
            true,  // multiple
            true   // requeue
        );
    }

    /**
     * @test
     * @dataProvider responseSignals
     **/
    public function it_throws_during_ACK_or_NACK_if_no_channel_was_set(string $signal)
    {
        $msg = Message::make([]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Message was not published by hopper (has no channel set) or response was already sent");
        $msg->{$signal}();
    }

    /**
     * @test
     * @dataProvider responseSignals
     **/
    public function it_throws_during_ACK_or_NACK_if_message_was_already_responded_to(string $signal)
    {
        $amqpMsg = Mockery::mock(AMQPMessage::class, ['getDeliveryTag' => 123]);
        $channel = Mockery::spy(AMQPChannel::class);

        $msg = new Message($amqpMsg);
        $msg->setChannel($channel);

        $msg->{$signal}();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Message was not published by hopper (has no channel set) or response was already sent");
        $msg->{$signal}();
    }

    public function messageData()
    {
        return [
            'array' => [['foo' => 'bar']],
            'JsonSerializable' => [new TestMessageJsonData],
        ];
    }

    public function responseSignals()
    {
        return [
            'ACK' => ['ack'],
            'NACK' => ['nack'],
        ];
    }
}

class TestMessageJsonData implements JsonSerializable
{
    public function jsonSerialize()
    {
        return ['foo' => 'bar'];
    }
}
