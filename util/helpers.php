<?php

if (!function_exists('debug')) {
    function debug(string $msg): void
    {
        // $output = __DIR__ . '/../test.log';
        $output = 'php://stdout';

        file_put_contents($output, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    }
}

if (!function_exists('dump')) {
    /**
     * @param mixed[] $args
     * @return void
     */
    function dump(...$args): void
    {
        var_dump(...$args);
    }
}

if (!function_exists('dd')) {
    /**
     * @param mixed[] $args
     * @return void
     */
    function dd(...$args): void
    {
        dump(...$args);
        die();
    }
}
