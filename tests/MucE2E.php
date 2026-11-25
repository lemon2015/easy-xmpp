<?php

/**
 * XEP-0045 MUC 端到端测试（标准协议：presence / message / muc#owner IQ）。
 *
 * 用法:
 *   php tests/MucE2E.php
 *
 * 环境变量（均可选）:
 *   XMPP_HOST        默认 example.com
 *   XMPP_PORT        默认 7222
 *   XMPP_USER        默认 test-user
 *   XMPP_PASSWORD    默认 test
 *   XMPP_MUC_SERVICE 默认 conference.<XMPP_HOST>
 *   XMPP_MUC_ROOM    默认 muc-e2e-<timestamp>
 *   XMPP_MUC_NICK    默认 e2e-<pid>
 *   XMPP_MUC_PEER_NICK 默认 peer-<pid>
 *   XMPP_TIMEOUT     默认 30 秒
 *   XMPP_LOG         默认 <tmp>/easy-xmpp-muc-e2e.log
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found, run: composer install\n");
    exit(2);
}
require $autoload;

use EasyXmpp\XEP\MUC;
use EasyXmpp\XMPP;
use Psr\Log\AbstractLogger;

function envValue(string $name, string $default): string
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

function pump($conn, string $eventName, float $seconds): array
{
    $out = [];
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $payloads = $conn->processUntil($eventName, max(0.01, $deadline - microtime(true)));
        if (!$payloads) {
            break;
        }
        foreach ($payloads as $payload) {
            $out[] = $payload[1];
        }
    }
    return $out;
}

function hasBody(array $messages, string $body): bool
{
    foreach ($messages as $message) {
        if (($message['body'] ?? null) === $body) {
            return true;
        }
    }
    return false;
}

function hasNick(array $events, string $nick): bool
{
    foreach ($events as $event) {
        if (($event['nick'] ?? null) === $nick) {
            return true;
        }
    }
    return false;
}

class MucE2ELogger extends AbstractLogger
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

$host = envValue('XMPP_HOST', 'example.com');
$port = (int)envValue('XMPP_PORT', '7222');
$user = envValue('XMPP_USER', 'test-user');
$password = envValue('XMPP_PASSWORD', 'test');
$service = envValue('XMPP_MUC_SERVICE', 'conference.' . $host);
$room = envValue('XMPP_MUC_ROOM', 'muc-e2e-' . time());
$nick = envValue('XMPP_MUC_NICK', 'e2e-' . getmypid());
$peerNick = envValue('XMPP_MUC_PEER_NICK', 'peer-' . getmypid());
$timeout = (int)envValue('XMPP_TIMEOUT', '30');
$logFile = envValue('XMPP_LOG', sys_get_temp_dir() . '/easy-xmpp-muc-e2e.log');

if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "invalid XMPP_PORT: $port\n");
    exit(2);
}
if ($timeout < 1) {
    fwrite(STDERR, "invalid XMPP_TIMEOUT: $timeout\n");
    exit(2);
}

@unlink($logFile);

$logger = new MucE2ELogger($logFile);
$config = [
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'password' => $password,
    'resource' => 'muc-e2e-' . getmypid(),
    'server' => '',
    'printlog' => false,
    'loglevel' => 7,
    'logfile' => $logFile,
    'logger' => $logger,
    'reconnect' => false,
];

echo "== EasyXmpp MUC E2E ==\n";
echo "server: $host:$port, service: $service, room: $room\n";
echo "log: $logFile\n";

try {
    $conn = new XMPP($config);
    $conn->connect($timeout, false, true);
    if (!$conn->processUntil('session_start', $timeout)) {
        throw new RuntimeException('session_start timeout');
    }
    $conn->presence('muc e2e');

    $muc = new MUC($conn, $service, $timeout);
    $result = $muc->createRoom($room, $nick, ['muc#roomconfig_persistentroom' => 0]);
    if (empty($result['created'])) {
        throw new RuntimeException('room was not created (status 201 missing)');
    }
    if (!$muc->isJoined($room)) {
        throw new RuntimeException('room is not marked as joined');
    }
    $occupants = $muc->getOccupants($room);
    if (!isset($occupants[$nick])) {
        throw new RuntimeException('self is missing from occupants');
    }
    echo "[1/5] create/join ok, occupants=" . count($occupants) . "\n";

    $body = 'hello from ' . $nick;
    $muc->sendGroupMessage($room, $body);
    if (!hasBody(pump($conn, 'muc_message', 5), $body)) {
        throw new RuntimeException('own group message was not echoed');
    }
    echo "[2/5] own group message echoed\n";

    $peerConfig = $config;
    $peerConfig['resource'] = 'muc-peer-' . getmypid();
    $peer = new XMPP($peerConfig);
    $peer->connect($timeout, false, true);
    if (!$peer->processUntil('session_start', $timeout)) {
        throw new RuntimeException('peer session_start timeout');
    }
    $peer->presence('muc peer');

    $peerMuc = new MUC($peer, $service, $timeout);
    $peerMuc->join($room, $peerNick);
    if (!hasNick(pump($conn, 'muc_occupant_joined', 5), $peerNick)) {
        throw new RuntimeException('occupant_joined for peer not received');
    }
    echo "[3/5] peer joined, occupant_joined received\n";

    $peerBody = 'hello from ' . $peerNick;
    $peerMuc->sendGroupMessage($room, $peerBody);
    if (!hasBody(pump($conn, 'muc_message', 5), $peerBody)) {
        throw new RuntimeException('peer group message not received');
    }
    echo "[4/5] peer group message received\n";

    $peerMuc->leave($room, $peerNick);
    if (!hasNick(pump($conn, 'muc_occupant_left', 5), $peerNick)) {
        throw new RuntimeException('occupant_left for peer not received');
    }
    $peer->disconnect();
    echo "[5/5] peer left, occupant_left received\n";

    if (!$muc->leave($room, $nick)) {
        throw new RuntimeException('self leave timeout');
    }
    $conn->disconnect();
    echo "MUC E2E OK\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "MUC E2E FAILED: " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "=== LOG TAIL ===\n" . implode('', array_slice($logger->lines, -40)));
    exit(1);
}
