<?php

require_once __DIR__ . "/bootstrap.php";

/** @var \Tsterker\Hopper\Hopper $hopper */

$hopper->declareExchange($exchange = $hopper->createExchange('the-exchange'));
$hopper->declareQueue($queue = $hopper->createQueue('the-queue'));

$hopper->bind($exchange, $queue);

$data = str_repeat('x', 1000000);  // 1MB string

$msg = TSterker\Hopper\Message::make(['data' => $data]);

$time = microtime(true);
$max = isset($argv[1]) ? (int) $argv[1] : 1;

$messages = [];
for ($i = 0; $i < $max; $i++) {
    $messages[] = TSterker\Hopper\Message::make(['data' => $data]);
}

$hopper->publishBatch($exchange, $messages);

echo microtime(true) - $time, "\n";
