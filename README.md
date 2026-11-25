# Easy-XMPPHP
XMPP library (based on xmpphp)

## 环境需求
PHP >= 8.0（已在 PHP 8.5 下验证）

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
        "reconnect"=>false, // 断线自动重连，默认 false，建议交给外部守护进程 [optional]
        "logfile"=>"/tmp/xmpp.log", // [optional]
        // "logger"=>$psrLogger, // 任意 Psr\Log\LoggerInterface 实现 [optional]
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


## 日志

默认使用内置 `EasyXmpp\Util\Log`：`printlog=true` 时通过 `error_log` 写入 `logfile`。

需要接入框架日志时，传入任意实现了 `Psr\Log\LoggerInterface` 的 logger（Laravel、Symfony、Monolog 等）即可，此时 `printlog`、`loglevel`、`logfile` 不再生效：

```php
$config['logger'] = $psrLogger;
$conn = new XMPP($config);
```

非 PSR-3 的框架日志类，先包一层 PSR-3 适配器再传入。

## 开发

```shell
composer install
composer cs      # 检查 PSR-12
composer cs:fix  # 自动修复
```

## 测试

```shell
php tests/E2E.php   # 或 composer e2e
```

默认连接 `example.com:7222`，账号密码随意。可用环境变量覆盖：`XMPP_HOST`、`XMPP_PORT`、`XMPP_USER`、`XMPP_PASSWORD`、`XMPP_RESOURCE`、`XMPP_SERVER`、`XMPP_TLS=0`、`XMPP_TIMEOUT`、`XMPP_LOG`。

## MUC 房间（XEP-0045）

只使用标准 XMPP 协议，不依赖 ejabberd 等服务器的管理 HTTP API。

```php
use EasyXmpp\XEP\MUC;

$muc = new MUC($conn, 'conference.example.com');

// 创建（进入不存在的房间会自动创建），可选房主配置
$result = $muc->createRoom('room1', 'nick', [
    'muc#roomconfig_persistentroom' => 0,
]);

// 进入已有房间
$muc->join('room1', 'nick');

// 发群消息 / 私聊
$muc->sendGroupMessage('room1', 'hello');
$muc->sendPrivateMessage('room1', 'other-nick', 'hi');

// 邀请 / 拒绝邀请 / 设置主题（XEP-0045 标准）
$muc->invite('room1', 'user@example.com', 'join us');
$muc->declineInvite('room1', 'inviter@example.com', 'not now');
$muc->setTopic('room1', 'today topic');

// 房间事件（join 后服务端会持续推送）
$conn->addEventHandler('muc_message', 'onMucMessage', $handler);
$conn->addEventHandler('muc_occupant_joined', 'onOccupantJoined', $handler);
$conn->addEventHandler('muc_occupant_left', 'onOccupantLeft', $handler);

// 离开
$muc->leave('room1', 'nick');
```

事件：`muc_joined`、`muc_left`、`muc_message`、`muc_private_message`、`muc_occupant_joined`、`muc_occupant_presence`、`muc_occupant_left`、`muc_subject`、`muc_invite`、`muc_invite_declined`、`muc_removed`、`muc_error`。

成员事件会附带 `affiliation`、`role`、`jid`、`newnick`（服务端可见时）。只实现 XEP-0045 的 mediated invitation；direct invitation（XEP-0249）和 `muc#admin` 踢人/封禁不在本包范围内。

标准 MUC 没有单独的“订阅房间”报文：join 后保持在房间内，服务端就会推送房间 presence 和 groupchat 消息。

## PubSub（XEP-0060）

只使用标准 IQ 和 message 事件通知，不依赖服务端管理接口。PEP（XEP-0163）把 service 设为用户 bare JID 即可。

```php
use EasyXmpp\XEP\PubSub;

$ps = new PubSub($conn, 'pubsub.example.com');

// 创建节点（可选配置）
$node = $ps->createNode('urn:example:news', [
    'pubsub#access_model' => 'open',
    'pubsub#persist_items' => true,
]);

// 发布 / 读取 / 撤回（payload 为合法 XML 片段）
$ps->publish($node, 'item1', '<news xmlns="urn:example:news">hello</news>');
$items = $ps->getItems($node);
$ps->retract($node, 'item1');

// 订阅 / 退订
$sub = $ps->subscribe($node);
$ps->unsubscribe($node, $sub['jid'] ?? null, $sub['subid'] ?? null);

// 节点管理
$ps->getSubscriptions($node);
$ps->getAffiliations($node);
$ps->getNodeConfig($node);
$ps->setNodeConfig($node, ['pubsub#max_items' => 20]);
$ps->deleteNode($node);
```

事件：`pubsub_items`、`pubsub_item`、`pubsub_retract`、`pubsub_purge`、`pubsub_config`、`pubsub_subscription`、`pubsub_affiliations`。

`getItems()` / 事件中的 item 结构：`id`、`publisher`、`payload`（第一个子元素 XMLObj，可能为 null）、`xml`（item 节点）。

## License

MIT