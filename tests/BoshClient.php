<?php
// todo  bug exists...
require dirname(__DIR__)."/vendor/autoload.php";

use EasyXmpp\BOSH;
use EasyXmpp\Util\Log;
use EasyXmpp\Exceptions\Exception;

$config = [
    "host"=>"munclewang.cn",
    "port"=>"5222",
    "user"=>"mark",
    "password"=>"123456",
    "resource"=>"cli",
    "server"=>"",// ÄÚÍøÅäip
    "printlog"=>true,
    "loglevel"=>Log::DEBUG,
    "timeout"=>30,
    "persistent"=>true
];

$conn = new BOSH($config);
$conn->autoSubscribe();

try {
    $conn->connect();
    while (!$conn->isDisconnected()) {
        $payloads = $conn->processUntil(array('message', 'presence', 'end_stream', 'session_start'));
        foreach ($payloads as $event) {
            $pl = $event[1];
            switch ($event[0]) {
                case 'message':
                    print "---------------------------------------------------------------------------------\n";
                    print "Message from: {$pl['from']}\n";
                    if ($pl['subject'])
                        print "Subject: {$pl['subject']}\n";
                    print $pl['body'] . "\n";
                    print "---------------------------------------------------------------------------------\n";
                    $conn->message($pl['from'], $body = "Thanks for sending me \"{$pl['body']}\".", $type = $pl['type']);
                    if ($pl['body'] == 'quit')
                        $conn->disconnect();
                    if ($pl['body'] == 'break')
                        $conn->send("</end>");
                    break;
                case 'presence':
                    print "Presence: {$pl['from']} [{$pl['show']}] {$pl['status']}\n";
                    break;
                case 'session_start':
                    print "Session Start\n";
                    $conn->getRoster();
                    $conn->presence($status = "Cheese!");
                    break;
            }
        }
    }
} catch (Exception $e) {
    die($e->getMessage());
}
