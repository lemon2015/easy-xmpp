<?php

// Copy this file to tests/config.local.php and fill in your own server.
// tests/config.local.php is gitignored and must not be committed.

return [
    'host' => 'xmpp.example.com',
    'port' => 5222,
    'user' => 'test-user',
    'password' => 'test-password',
    'resource' => 'cli',
    'server' => '',
    'printlog' => true,
    'loglevel' => \EasyXmpp\Util\Log::DEBUG,
    'logfile' => '/tmp/xmpp-test.log',
    // 'reconnect' => true, // tests/Forward.php 可按需开启
];
