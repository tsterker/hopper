<?php

define('HOST', getenv('TEST_RABBITMQ_HOST') ? getenv('TEST_RABBITMQ_HOST') : 'localhost');
define('PORT', getenv('TEST_RABBITMQ_PORT') ? getenv('TEST_RABBITMQ_PORT') : '5672');
define('API_PORT', getenv('TEST_RABBITMQ_API_PORT') ? getenv('TEST_RABBITMQ_API_PORT') : '15672');
define('USER', getenv('TEST_RABBITMQ_USER') ? getenv('TEST_RABBITMQ_USER') : 'user');
define('PASS', getenv('TEST_RABBITMQ_PASS') ? getenv('TEST_RABBITMQ_PASS') : 'pass');
define('VHOST', '/');
// define('AMQP_DEBUG', getenv('TEST_AMQP_DEBUG') !== false ? (bool)getenv('TEST_AMQP_DEBUG') : false);

define('TOXI_HOST', getenv('TOXI_HOST') ? getenv('TOXI_HOST') : '');
// define('TOXI_HOST', getenv('TOXI_HOST') ? getenv('TOXI_HOST') : '127.0.0.1');
define('TOXI_PORT', getenv('TOXI_PORT') ? getenv('TOXI_PORT') : '');
define('TOXI_AMQP_PORT', getenv('TOXI_AMQP_PORT') ? getenv('TOXI_AMQP_PORT') : '');

// define('TOXI_PORT', getenv('TOXI_PORT') ? getenv('TOXI_PORT') : '8474');
