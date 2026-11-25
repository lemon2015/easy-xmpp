<?php

/**
 * XEP-0060 PubSub 端到端测试（标准 IQ + message 事件通知）。
 *
 * 用法:
 *   php tests/PubSubE2E.php
 *
 * 环境变量（均可选）:
 *   XMPP_HOST          默认 example.com
 *   XMPP_PORT          默认 7222
 *   XMPP_USER          默认 pubsub-user_<time>
 *   XMPP_PASSWORD      默认 test
 *   XMPP_PUBSUB_SERVICE 默认 pubsub.<XMPP_HOST>
 *   XMPP_PUBSUB_NODE   默认 easyxmpp-test-<time>
 *   XMPP_TIMEOUT       默认 30 秒
 *   XMPP_LOG           默认 <tmp>/easy-xmpp-pubsub-e2e.log
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found, run: composer install\n");
    exit(2);
}
require $autoload;

use EasyXmpp\XEP\PubSub;
use EasyXmpp\XMPP;
use Psr\Log\AbstractLogger;

function envValue(string $name, string $default): string
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

function hasItem(array $items, string $id): bool
{
    foreach ($items as $item) {
        if (($item['id'] ?? null) === $id) {
            return true;
        }
    }
    return false;
}

class PubSubE2ELogger extends AbstractLogger
{
    /** @var string[] */
    public $lines = [];
    /** @var string */
    private $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $line = date('Y-m-d H:i:s') . " [$level] " . (string)$message . "\n";
        $this->lines[] = $line;
        @file_put_contents($this->file, $line, FILE_APPEND);
    }
}

class PubSubCollector
{
    public $items = [];
    public function onItem($payload) { $this->items[] = $payload; }
}

$host = envValue('XMPP_HOST', 'example.com');
$port = (int)envValue('XMPP_PORT', '7222');
$stamp = time();
$user = envValue('XMPP_USER', 'pubsub-user_' . $stamp);
$password = envValue('XMPP_PASSWORD', 'test');
$service = envValue('XMPP_PUBSUB_SERVICE', 'pubsub.' . $host);
$node = envValue('XMPP_PUBSUB_NODE', 'easyxmpp-test-' . $stamp);
$timeout = (int)envValue('XMPP_TIMEOUT', '30');
$logFile = envValue('XMPP_LOG', sys_get_temp_dir() . '/easy-xmpp-pubsub-e2e.log');
$userJid = $user . '@' . $host;

if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "invalid XMPP_PORT: $port\n");
    exit(2);
}
if ($timeout < 1) {
    fwrite(STDERR, "invalid XMPP_TIMEOUT: $timeout\n");
    exit(2);
}

@unlink($logFile);

$logger = new PubSubE2ELogger($logFile);
$config = [
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'password' => $password,
    'resource' => 'pubsub-e2e-' . getmypid(),
    'server' => '',
    'printlog' => false,
    'loglevel' => 7,
    'logfile' => $logFile,
    'logger' => $logger,
    'reconnect' => false,
];

echo "== EasyXmpp PubSub E2E ==\n";
echo "server: $host:$port, service: $service, node: $node\n";
echo "log: $logFile\n";

try {
    $ps = null;
    $conn = new XMPP($config);
    $conn->connect($timeout, false, true);
    if (!$conn->processUntil('session_start', $timeout)) {
        throw new RuntimeException('session_start timeout');
    }
    $conn->presence('pubsub e2e');

    $ps = new PubSub($conn, $service, $timeout);
    $collector = new PubSubCollector();
    $conn->addEventHandler('pubsub_item', 'onItem', $collector);

    $features = $ps->features($ps->discover());
    if (!in_array('http://jabber.org/protocol/pubsub#publish', $features, true)) {
        throw new RuntimeException('service does not advertise pubsub#publish');
    }
    if (!in_array('http://jabber.org/protocol/pubsub#subscribe', $features, true)) {
        throw new RuntimeException('service does not advertise pubsub#subscribe');
    }
    echo "[1/8] discover ok, features=" . count($features) . "\n";

    $created = $ps->createNode($node, [
        'pubsub#access_model' => 'open',
        'pubsub#persist_items' => true,
        'pubsub#max_items' => 10,
    ]);
    if ($created !== $node) {
        throw new RuntimeException('unexpected created node: ' . var_export($created, true));
    }
    echo "[2/8] node created\n";

    $itemId = $ps->publish($node, 'item1', '<test xmlns="urn:easyxmpp:test">hello</test>');
    if ($itemId !== 'item1') {
        throw new RuntimeException('unexpected publish item id: ' . var_export($itemId, true));
    }
    $items = $ps->getItems($node);
    if (!hasItem($items, 'item1')) {
        throw new RuntimeException('item1 not found after publish');
    }
    $payload = $items[0]['payload'] ?? null;
    if (!$payload || $payload->name !== 'test' || $payload->data !== 'hello') {
        throw new RuntimeException('item1 payload mismatch');
    }
    echo "[3/8] publish/getItems ok\n";

    $subscription = $ps->subscribe($node, $userJid);
    if (($subscription['subscription'] ?? '') !== 'subscribed') {
        throw new RuntimeException('subscription not active: ' . var_export($subscription, true));
    }
    echo "[4/8] subscribe ok, subid=" . ($subscription['subid'] ?? '-') . "\n";

    $ps->publish($node, 'item2', '<test xmlns="urn:easyxmpp:test">two</test>');
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline && !hasItem($collector->items, 'item2')) {
        $conn->processUntil('pubsub_item', 0.5);
    }
    if (!hasItem($collector->items, 'item2')) {
        throw new RuntimeException('pubsub_item event for item2 not received');
    }
    echo "[5/8] event notification received\n";

    $subscriptions = $ps->getSubscriptions($node);
    $found = false;
    foreach ($subscriptions as $sub) {
        if (($sub['node'] ?? '') === $node) {
            $found = true;
        }
    }
    if (!$found) {
        throw new RuntimeException('subscription list does not contain node');
    }
    echo "[6/8] getSubscriptions ok\n";

    $ps->retract($node, 'item2');
    if (hasItem($ps->getItems($node), 'item2')) {
        throw new RuntimeException('item2 still present after retract');
    }
    echo "[7/8] retract ok\n";

    $ps->unsubscribe($node, $subscription['jid'] ?? $userJid, $subscription['subid'] ?? null);
    $ps->deleteNode($node);
    echo "[8/8] unsubscribe/delete node ok\n";

    $conn->disconnect();
    echo "PUBSUB E2E OK\n";
    exit(0);
} catch (Throwable $e) {
    if ($ps !== null) {
        try {
            $ps->deleteNode($node);
        } catch (Throwable $ignored) {
            // best effort cleanup
        }
    }
    fwrite(STDERR, "PUBSUB E2E FAILED: " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "=== LOG TAIL ===\n" . implode('', array_slice($logger->lines, -50)));
    exit(1);
}
