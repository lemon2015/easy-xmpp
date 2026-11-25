<?php

namespace EasyXmpp\Util;

use Psr\Log\InvalidArgumentException;

/**
 * 默认日志实现：printlog 打开时通过 error_log 写入 logfile。
 *
 * 需要接入框架日志时，不必使用本类，直接把实现了 Psr\Log\LoggerInterface
 * 的框架 logger 传给 XMPP 配置的 logger 项即可。
 */
class Log implements LoggerInterface
{
    public const EMERGENCY = 0;
    public const ALERT = 1;
    public const CRITICAL = 2;
    public const ERROR = 3;
    public const WARNING = 4;
    public const NOTICE = 5;
    public const INFO = 6;
    public const DEBUG = 7;

    private const LEVEL_NAMES = array(
        self::EMERGENCY => 'emergency',
        self::ALERT => 'alert',
        self::CRITICAL => 'critical',
        self::ERROR => 'error',
        self::WARNING => 'warning',
        self::NOTICE => 'notice',
        self::INFO => 'info',
        self::DEBUG => 'debug',
    );

    private const LEVEL_SEVERITY = array(
        'emergency' => self::EMERGENCY,
        'alert' => self::ALERT,
        'critical' => self::CRITICAL,
        'error' => self::ERROR,
        'warning' => self::WARNING,
        'notice' => self::NOTICE,
        'info' => self::INFO,
        'debug' => self::DEBUG,
    );

    /**
     * @var array
     */
    protected $data = array();
    /**
     * @var integer
     */
    protected $runlevel;
    /**
     * @var boolean
     */
    protected $printout;
    /**
     * @var string
     */
    protected $logfile;

    /**
     * @param boolean $printout
     * @param int     $runlevel 最低输出级别，取值见 self::EMERGENCY ~ self::DEBUG
     * @param string  $logfile
     */
    public function __construct($printout = false, $runlevel = self::NOTICE, $logfile = "")
    {
        $this->printout = (bool)$printout;
        $this->runlevel = $runlevel === null ? self::NOTICE : (int)$runlevel;
        $this->logfile = $logfile;
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param string $level PSR-3 级别名
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $name = $this->normalizeLevel($level);
        if ($this->printout && self::LEVEL_SEVERITY[$name] <= $this->runlevel) {
            $this->writeLine((string)$message, $name, time());
        }
    }

    protected function normalizeLevel($level): string
    {
        if (is_string($level)) {
            $name = strtolower($level);
            if (isset(self::LEVEL_SEVERITY[$name])) {
                return $name;
            }
        }
        throw new InvalidArgumentException('Invalid log level: ' . var_export($level, true));
    }

    protected function writeLine($msg, $levelName, $time)
    {
        $log = date('Y-m-d H:i:s', $time) . " [" . strtoupper($levelName) . "] " . $msg . PHP_EOL;
        $logfile = $this->logfile ?: "/tmp/xmpp.log";
        error_log($log, 3, $logfile);
    }

    /**
     * Output the complete log.
     * Log will be cleared if $clear = true
     *
     * @param boolean $clear
     * @param integer $runlevel
     */
    public function printout($clear = true, $runlevel = null)
    {
        if ($runlevel === null) {
            $runlevel = $this->runlevel;
        }
        $runlevel = (int)$runlevel;
        foreach ($this->data as $data) {
            if ($runlevel <= $data[0]) {
                $this->writeLine($data[1], self::LEVEL_NAMES[$data[0]] ?? 'notice', $data[2]);
            }
        }
        if ($clear) {
            $this->data = array();
        }
    }
}
