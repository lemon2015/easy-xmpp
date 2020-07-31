<?php

namespace EasyXmpp\Util;

class Log
{
    const EMERGENCY = 0;
    const ALERT = 1;
    const CRITICAL = 2;
    const ERROR = 3;
    const WARNING = 4;
    const NOTICE = 5;
    const INFO = 6;
    const DEBUG = 7;

    /**
     * @var array
     */
    protected $data = array();
    /**
     * @var array
     */
    protected $names = array('EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG');
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
     * Constructor
     *
     * @param boolean $printout
     * @param string $runlevel
     */
    public function __construct($printout = false, $runlevel = self::NOTICE, $logfile = "")
    {
        $this->printout = (boolean)$printout;
        $this->runlevel = (int)$runlevel;
        $this->logfile = $logfile;
    }

    /**
     * Add a message to the log data array
     * If printout in this instance is set to true, directly output the message
     *
     * @param string $msg
     * @param integer $runlevel
     */
    public function log($msg, $runlevel = self::NOTICE)
    {
        $time = time();
        if ($this->printout and $runlevel <= $this->runlevel) {
            $this->writeLine($msg, $runlevel, $time);
        }
    }

    protected function writeLine($msg, $runlevel, $time)
    {
        $log = date('Y-m-d H:i:s', $time) . " [" . $this->names[$runlevel] . "] " . $msg . PHP_EOL;
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
        foreach ($this->data as $data) {
            if ($runlevel <= $data[0]) {
                $this->writeLine($data[1], $runlevel, $data[2]);
            }
        }
        if ($clear) {
            $this->data = array();
        }
    }

}