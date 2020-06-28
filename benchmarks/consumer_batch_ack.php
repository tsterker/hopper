<?php

use TSterker\Hopper\Message;

require_once __DIR__ . "/bootstrap.php";

/** @var \Tsterker\Hopper\Hopper $hopper */

$hopper->setPrefetchCount(0);

$hopper->declareQueue($queue = $hopper->createQueue('the-queue'));
$hopper->declareExchange($exchange = $hopper->createExchange('the-exchange'));

$hopper->bind($exchange, $queue);

$max = isset($argv[1]) ? (int) $argv[1] : 1;

echo "Producing $max messages...\n";
$data = str_repeat('x', 1000000);  // 1MB string
$messages = [];
for ($i = 0; $i < $max; $i++) {
    $messages[] = TSterker\Hopper\Message::make(['data' => $data]);
}
$hopper->publishBatch($queue, $messages);
$hopper->awaitPendingPublishConfirms();

$time = microtime(true);

$lastMessage;
$hopper->subscribe($queue, function ($msg) use (&$lastMessage) {
    $lastMessage = $msg;
});

echo "Start consuming...\n";
while ($max-- > 0) {
    // echo "$max\n";
    $hopper->getChannel()->wait(null, false);
}

$lastMessage->ack(true);

echo microtime(true) - $time, "\n";
