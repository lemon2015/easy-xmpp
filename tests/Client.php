<?php

require dirname(__DIR__)."/vendor/autoload.php";

use EasyXmpp\XMPP;
use EasyXmpp\Util\Log;
use EasyXmpp\Exceptions\Exception;
use EasyXmpp\Support\Config;

$host = 'munclewang.cn';
$port = 5222;
$user = 'mark1';
$pwd = 123456;
$conn = new XMPP($host, $port, $user, $pwd, 'xmpp', '', $printlog = true, $loglevel = Log::DEBUG);
$conn->autoSubscribe();

$vcard_request = array();

try {
    $conn->connect();
    while (!$conn->isDisconnected()) {
        $payloads = $conn->processUntil(array('message', 'presence', 'end_stream', 'session_start', 'vcard','reconnect'));
        foreach ($payloads as $event) {
            $pl = $event[1];
            switch ($event[0]) {
                case 'end_stream':
                    print "receive end stream";
                    break;
                case 'reconnect':
                    print "receive reconnect".PHP_EOL;
                    print_r($pl);
                    break;
                case 'message':
                    print "---------------------------------------------------------------------------------\n";
                    print "Message from: {$pl['from']}\n";
                    if (isset($pl['subject']))
                        print "Subject: {$pl['subject']}\n";
                    print $pl['body'] . "\n";
                    print "---------------------------------------------------------------------------------\n";
                    $conn->message($pl['from'], $body = "Thanks for sending me \"{$pl['body']}\".", $type = $pl['type']);
                    $cmd = explode(' ', $pl['body']);
                    if ($cmd[0] == 'quit')
                        $conn->disconnect();
                    if ($cmd[0] == 'break')
                        $conn->send("</end>");
                    if ($cmd[0] == 'vcard') {
                        if (!($cmd[1]))
                            $cmd[1] = $conn->user . '@' . $conn->server;
                        // take a note which user requested which vcard
                        $vcard_request[$pl['from']] = $cmd[1];
                        // request the vcard
                        $conn->getVCard($cmd[1]);
                    }
                    break;
                case 'presence':
                    print "Presence: {$pl['from']} [{$pl['show']}] {$pl['status']}\n";
                    break;
                case 'session_start':
                    print "Session Start\n";
                    $conn->getRoster();
                    $conn->presence($status = "Cheese!");
                    break;
                case 'vcard':
                    // check to see who requested this vcard
                    $deliver = array_keys($vcard_request, $pl['from']);
                    // work through the array to generate a message
                    print_r($pl);
                    $msg = '';
                    foreach ($pl as $key => $item) {
                        $msg .= "$key: ";
                        if (is_array($item)) {
                            $msg .= "\n";
                            foreach ($item as $subkey => $subitem) {
                                $msg .= "  $subkey: $subitem\n";
                            }
                        } else {
                            $msg .= "$item\n";
                        }
                    }
                    // deliver the vcard msg to everyone that requested that vcard
                    foreach ($deliver as $sendjid) {
                        // remove the note on requests as we send out the message
                        unset($vcard_request[$sendjid]);
                        $conn->message($sendjid, $msg, 'chat');
                    }
                    break;
            }
        }
    }
} catch (Exception $e) {
    die($e->getMessage().PHP_EOL);
}
