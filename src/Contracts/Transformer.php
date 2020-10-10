<?php

namespace TSterker\Hopper\Contracts;

use TSterker\Hopper\Message;

interface Transformer
{
    /**
     * Implement "message transformer" logic.
     * 
     * Method receives an incoming message and should returns an outgoing
     * message, where the incoming message shuold only be ACKed once the
     * publish of the outgoing message was confirmed.
     * 
     * NOTE: The message transformer should not ACK messages itself!
     * TODO: Prevent transformer to "accidentally" ACK messages?
     *
     * @param Message $msg Inoming message that should be confirmed once outgoing message publish was confirmed
     * @return null|Message Outgoing message to publish and (on success) should cause incoming message to be ACKed
     *                      Return null to indicate the incoming message should be acknowledged & not requeued (i.e. NACK)
     */
    public function transformMessage(Message $msg): ?Message;
}
