<?php

/**
 * 模拟 forwardKfMsgToWx.php 中 XmppListener 的 XMPP 连接和消息监听流程
 *
 * 用法: php tests/ForwarderTest.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use EasyXmpp\XMPP;
use EasyXmpp\Util\Log;
use EasyXmpp\Exceptions\Exception;

// ---- 配置 ----
$config = [
    'host'     => 'test2.bangwo8.com',
    'port'     => 7222,
    'user'     => 'kf_bw8admin_wx',
    'password' => 'UtilisateurDieu666',
    'resource' => 'forwarder-test',
    'server'   => 'test2.bangwo8.com',
    'printlog' => true,
    'loglevel' => Log::DEBUG,
    'logfile'  => '/tmp/xmpp-forwarder-test.log',
];

$stat = ['recv' => 0, 'skip' => 0];

// ---- 事件处理对象（addEventHandler 要求 obj->methodName($payload) 模式） ----
class ForwarderHandler
{
    public $stat = ['recv' => 0, 'skip' => 0];
    public $xmpp;

    public function onMessage($payload)
    {
        $this->stat['recv']++;
        echo sprintf(
            "[message] from=%s, type=%s, body=%s\n",
            $payload['from'] ?? '-',
            $payload['type'] ?? '-',
            substr($payload['body'] ?? '', 0, 80)
        );
    }

    public function onPresence($payload)
    {
        echo sprintf(
            "[presence] from=%s, show=%s, status=%s\n",
            $payload['from'] ?? '-',
            $payload['show'] ?? '-',
            $payload['status'] ?? '-'
        );
    }

    public function onReconnect($payload)
    {
        echo "[reconnect] reconnected, re-sending presence\n";
        $this->xmpp->presence();
    }
}

$handler = new ForwarderHandler();

echo "=== XMPP Forwarder Test ===\n";
echo sprintf("Connecting to %s:%d as %s\n", $config['host'], $config['port'], $config['user']);

try {
    $xmpp = new XMPP($config);

    // 注册事件
    $xmpp->addEventHandler('message', 'onMessage', $handler);
    $xmpp->addEventHandler('presence', 'onPresence', $handler);
    $xmpp->addEventHandler('reconnect', 'onReconnect', $handler);
    $handler->xmpp = $xmpp;

    // ---- 连接 & 登录 ----
    $xmpp->connect();
    $payloads = $xmpp->processUntil('session_start', 10);

    $loginOk = false;
    foreach ($payloads as $event) {
        if ($event[0] === 'session_start') {
            $xmpp->presence();
            echo sprintf("Login success, user=%s\n", $xmpp->user);
            $loginOk = true;
            break;
        }
    }

    if (!$loginOk) {
        echo "Login failed: session_start timeout\n";
        exit(1);
    }

    // ---- 消息循环 ----
    $lastPing = time();
    $pingInterval = 60;

    echo "Entering message loop (Ctrl+C to stop)...\n";

    while (!$xmpp->isDisconnected()) {
        $payloads = $xmpp->processUntil(['message', 'presence', 'reconnect'], 60);

        if (empty($payloads)) {
            // 空闲时发心跳
            if (time() - $lastPing >= $pingInterval) {
                $xmpp->send(' ');
                $lastPing = time();
                echo "[ping] whitespace keepalive\n";
            }
            continue;
        }

        $lastPing = time();
        // 事件已由 addEventHandler 注册的 handler 处理，此处无需再 dispatch
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}