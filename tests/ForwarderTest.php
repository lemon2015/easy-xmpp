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
    'host'     => 'example.com',
    'port'     => 7222,
    'user'     => 'test-user',
    'password' => 'REDACTED',
    'resource' => 'forwarder-test',
    'server'   => 'example.com',
    'printlog' => true,
    'loglevel' => Log::DEBUG,
    'logfile'  => '/tmp/xmpp-forwarder-test.log',
];

$stat = ['recv' => 0, 'skip' => 0];

// ---- 事件处理对象（addEventHandler 要求 obj->methodName($payload) 模式） ----
function ts()
{
    return date('H:i:s');
}

class ForwarderHandler
{
    public $stat = ['recv' => 0, 'skip' => 0];
    public $xmpp;

    public function onMessage($payload)
    {
        $this->stat['recv']++;
        echo sprintf(
            "[%s] [message] from=%s, type=%s, body=%s\n",
            ts(), $payload['from'] ?? '-', $payload['type'] ?? '-',
            substr($payload['body'] ?? '', 0, 80)
        );
    }

    public function onPresence($payload)
    {
        echo sprintf(
            "[%s] [presence] from=%s, show=%s, status=%s\n",
            ts(), $payload['from'] ?? '-', $payload['show'] ?? '-', $payload['status'] ?? '-'
        );
    }

    public function onReconnect($payload)
    {
        echo sprintf("[%s] [reconnect] TCP reconnected, waiting for SASL auth...\n", ts());
        // 不能在这里发 presence！重连后 SASL 认证还没走完
        // 等 session_start 事件再发 presence
    }
}

$handler = new ForwarderHandler();

echo sprintf("[%s] === XMPP Forwarder Test ===\n", ts());
echo sprintf("[%s] Connecting to %s:%d as %s\n", ts(), $config['host'], $config['port'], $config['user']);

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
            echo sprintf("[%s] Login success, user=%s\n", ts(), $xmpp->user);
            $loginOk = true;
            break;
        }
    }

    if (!$loginOk) {
        echo sprintf("[%s] Login failed: session_start timeout\n", ts());
        exit(1);
    }

    // ---- 消息循环 ----
    $lastPing = time();
    $pingInterval = 60;

    echo sprintf("[%s] Entering message loop (Ctrl+C to stop)...\n", ts());

    while (!$xmpp->isDisconnected()) {
        $payloads = $xmpp->processUntil(['message', 'presence', 'reconnect', 'session_start'], 60);

        if (empty($payloads)) {
            // 空闲时发心跳
            if (time() - $lastPing >= $pingInterval) {
                $xmpp->send(' ');
                $lastPing = time();
                echo sprintf("[%s] [ping] whitespace keepalive\n", ts());
            }
            continue;
        }

        $lastPing = time();

        // 处理 session_start（重连后重新认证完成）
        foreach ($payloads as $event) {
            if ($event[0] === 'session_start') {
                $xmpp->presence();
                echo sprintf("[%s] [session_start] auth complete, presence sent\n", ts());
            }
        }
        // 其他事件已由 addEventHandler 注册的 handler 处理
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}