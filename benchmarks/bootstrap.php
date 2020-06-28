<?php

require_once __DIR__ . "/../vendor/autoload.php";

use PhpAmqpLib\Connection\AMQPLazyConnection;
use TSterker\Hopper\Hopper;

$hopper = new Hopper(
    new AMQPLazyConnection('localhost', 5672, 'user', 'pass')
);
