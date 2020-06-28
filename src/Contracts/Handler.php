<?php

namespace TSterker\Hopper\Contracts;

use TSterker\Hopper\Message;

interface Handler
{
    public function handleMessage(Message $msg): void;
}
