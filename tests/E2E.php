<?php

/**
 * 端到端测试：连接真实 XMPP 服务器，验证登录、presence、roster、ping/pong，
 * 以及断线后默认不自动重连。
 *
 * 用法:
 *   php tests/E2E.php
 *
 * 环境变量:
 *   XMPP_HOST      必填
 *   XMPP_PORT      默认 5222
 *   XMPP_USER      必填
 *   XMPP_PASSWORD  可选（为空则匿名）
 *   XMPP_RESOURCE  默认 e2e-<pid>
 *   XMPP_SERVER    默认空（使用 host）
 *   XMPP_TLS       默认 1，设为 0 关闭 STARTTLS
 *   XMPP_TIMEOUT   默认 30 秒
 *   XMPP_LOG       默认 <tmp>/easy-xmpp-e2e.log
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found, run: composer install\n");
    exit(2);
}
require $autoload;

use EasyXmpp\XMPP;
use Psr\Log\AbstractLogger;

function envValue(string $name, string $default): string
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

function hasEvent(array $payloads, string $name): bool
{
    foreach ($payloads as $event) {
        if (($event[0] ?? null) === $name) {
            return true;
        }
    }
    return false;
}

function containsReconnectLog(array $lines): bool
{
    foreach ($lines as $line) {
        if (stripos($line, 'Reconnecting') !== false) {
            return true;
        }
    }
    return false;
}

class E2ECaptureLogger extends AbstractLogger
{
    /** @var string[] */
    public $lines = [];
    /** @var string */
    private $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function log($level, $message, array $context = []): void
    {
        $line = date('Y-m-d H:i:s') . " [$level] " . (string)$message . "\n";
        $this->lines[] = $line;
        @file_put_contents($this->file, $line, FILE_APPEND);
    }
}

class E2EXMPP extends XMPP
{
    public function reconnectEnabled(): bool
    {
        return $this->reconnect;
    }

    public function simulateAbruptDrop(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}

$host = envValue('XMPP_HOST', '');
$port = (int)envValue('XMPP_PORT', '5222');
$user = envValue('XMPP_USER', '');
$password = envValue('XMPP_PASSWORD', '');
if ($host === '' || $user === '') {
    fwrite(STDERR, "XMPP_HOST and XMPP_USER are required\n");
    exit(2);
}
$resource = envValue('XMPP_RESOURCE', 'e2e-' . getmypid());
$server = envValue('XMPP_SERVER', '');
$tls = envValue('XMPP_TLS', '1') !== '0';
$timeout = (int)envValue('XMPP_TIMEOUT', '30');
$logFile = envValue('XMPP_LOG', sys_get_temp_dir() . '/easy-xmpp-e2e.log');

if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "invalid XMPP_PORT: $port\n");
    exit(2);
}
if ($timeout < 1) {
    fwrite(STDERR, "invalid XMPP_TIMEOUT: $timeout\n");
    exit(2);
}

@unlink($logFile);

$logger = new E2ECaptureLogger($logFile);
$config = [
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'password' => $password,
    'resource' => $resource,
    'server' => $server,
    'printlog' => false,
    'loglevel' => 7,
    'logfile' => $logFile,
    'logger' => $logger,
];

echo "== EasyXmpp E2E ==\n";
echo "server: $host:$port, user: $user, tls: " . ($tls ? 'yes' : 'no') . "\n";
echo "log: $logFile\n";

try {
    $conn = new E2EXMPP($config);
    $conn->useEncryption($tls);

    if ($conn->reconnectEnabled()) {
        throw new RuntimeException('reconnect should default to false');
    }
    echo "[1/6] reconnect default: off\n";

    $conn->connect($timeout, false, true);
    echo "[2/6] connected\n";

    $payloads = $conn->processUntil('session_start', $timeout);
    if (!hasEvent($payloads, 'session_start')) {
        throw new RuntimeException('session_start not received');
    }
    echo "[3/6] session_start: ok\n";

    $conn->presence('e2e online');
    echo "[4/6] presence: sent\n";

    $conn->getRoster();
    $conn->ping();
    $pong = $conn->processUntil('pong', $timeout);
    if (!hasEvent($pong, 'pong')) {
        throw new RuntimeException('pong not received');
    }
    echo "[5/6] roster requested, ping/pong: ok\n";

    $conn->simulateAbruptDrop();
    $conn->processOnce(0);
    if (!$conn->isDisconnected()) {
        throw new RuntimeException('connection should be marked disconnected after drop');
    }
    if (containsReconnectLog($logger->lines)) {
        throw new RuntimeException('auto reconnect should not run when reconnect=false');
    }
    echo "[6/6] abrupt drop: disconnected, no auto reconnect\n";

    echo "E2E OK\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "E2E FAILED: " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "=== LOG TAIL ===\n" . implode('', array_slice($logger->lines, -30)));
    exit(1);
}
