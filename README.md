# Easy-XMPPHP
XMPP library (based on xmpphp)

## 环境需求
PHP > 5.x

## 安装

```shell
$ composer require "mark-fish/easy-xmpp"
```

## 使用

```php
use EasyXmpp\XMPP;
use EasyXmpp\Util\Log;

$config = [
        "host"=>"",         // host [required]
        "port"=>"",         // tcp port in server config file [required]
        "user"=>"",         // register user account [required]
        "password"=>"",     // password [required]
        "resource"=>"",     // resource [optional]
        "server"=>"",       // ip [optional]
        "printlog"=>true,   // debug [optional]
        "loglevel"=>Log::DEBUG, // debug level [optional]
        "timeout"=>30,      // connect timeout (s) [optional]
        "persistent"=>true,  // connect persist [optional]
        "logfile"=>"/tmp/xmpp.log" // [optional]
];

$conn = new XMPP($config);
$conn->autoSubscribe();
$vcard_request = array();
try {
    $conn->connect($config['timeout'],$config['persistent']);
    while (!$conn->isDisconnected()) {
        $payloads = $conn->processUntil(array('message', 'presence', 'end_stream', 'session_start', 'vcard','reconnect'));
        foreach ($payloads as $event) {
            $pl = $event[1];
            switch ($event[0]) {
                case 'end_stream':
                    echo "receive end stream".PHP_EOL;
                    break;
                case 'reconnect':
                    echo "receive reconnect".PHP_EOL;
                    break;
                case 'message':
                    echo "---------------------------------------------------------------------------------".PHP_EOL;
                    echo "Message from: {$pl['from']}\n";
                    if (isset($pl['subject']))
                        echo "Subject: {$pl['subject']}\n";
                    echo $pl['body'] . "\n";
                    echo "---------------------------------------------------------------------------------".PHP_EOL;
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
                    echo "Presence: {$pl['from']} [{$pl['show']}] {$pl['status']}\n";
                    break;
                case 'session_start':
                    echo "Session Start\n";
                    $conn->getRoster();
                    $conn->presence($status = "Cheese!");
                    break;
                case 'vcard':
                    // check to see who requested this vcard
                    $deliver = array_keys($vcard_request, $pl['from']);
                    // work through the array to generate a message
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
```


## License

MIT