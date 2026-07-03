<?php

namespace EasyXmpp\Util;

interface LoggerInterface
{
    /**
     * Log a message at the given level.
     *
     * @param string $message
     * @param int    $level   One of the Log::EMERGENCY, ..., Log::DEBUG constants
     */
    public function log($message, $level);
}