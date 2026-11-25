<?php

namespace EasyXmpp\XEP;

use EasyXmpp\Exceptions\Exception;
use EasyXmpp\Util\XMLObj;
use EasyXmpp\XMPP;

/**
 * XEP-0045 Multi-User Chat（房间）最小实现。
 *
 * 只使用标准 XMPP 协议（presence / message / IQ），不依赖任何服务端管理接口。
 * 覆盖：创建/配置房间、进/出房间、群消息与私聊消息、房间成员与事件订阅。
 */
class MUC
{
    public const NS_MUC = 'http://jabber.org/protocol/muc';
    public const NS_MUC_USER = 'http://jabber.org/protocol/muc#user';
    public const NS_MUC_OWNER = 'http://jabber.org/protocol/muc#owner';
    public const NS_X_DATA = 'jabber:x:data';

    /**
     * @var XMPP
     */
    protected $conn;
    /**
     * @var string
     */
    protected $service;
    /**
     * @var int
     */
    protected $timeout;
    /**
     * roomJid => ['nick' => string, 'joined' => bool, 'occupants' => [nick => payload]]
     *
     * @var array
     */
    protected $rooms = array();

    public function __construct(XMPP $conn, $service, $timeout = 10)
    {
        if (!is_string($service) || trim($service) === '') {
            throw new \InvalidArgumentException('MUC service is required');
        }
        $this->conn = $conn;
        $this->service = trim($service);
        $this->timeout = (int)$timeout;
        $conn->addEventHandler('presence', 'handlePresence', $this);
        $conn->addEventHandler('message', 'handleMessage', $this);
    }

    /**
     * 加入房间；房间不存在时服务端会创建（status 201）。
     *
     * @return array 自己进入房间的 presence payload（含 room/nick/codes/created）
     */
    public function join($room, $nick = null, $password = null, $timeout = null)
    {
        $roomJid = $this->roomJid($room);
        $nick = $this->nick($nick);
        $timeout = $this->normalizeTimeout($timeout);

        $x = "<x xmlns='" . self::NS_MUC . "'>";
        if ($password !== null && $password !== '') {
            $x .= '<password>' . htmlspecialchars((string)$password) . '</password>';
        }
        $x .= '</x>';

        $this->rooms[$roomJid] = array(
            'nick' => $nick,
            'joined' => false,
            'occupants' => array(),
        );

        $this->conn->send(
            "<presence to='" . $this->xmlAttr($roomJid) . '/' . $this->xmlAttr($nick) . "'>" . $x . '</presence>'
        );

        try {
            $events = $this->conn->processUntil(array('muc_joined', 'muc_error'), $timeout);
        } catch (\Throwable $e) {
            unset($this->rooms[$roomJid]);
            throw $e;
        }

        foreach ($events as $event) {
            if ($event[0] === 'muc_error') {
                unset($this->rooms[$roomJid]);
                throw new Exception('MUC join failed: ' . $this->describeError($event[1]));
            }
        }
        if (!$events) {
            unset($this->rooms[$roomJid]);
            throw new Exception('MUC join timeout: ' . $roomJid);
        }
        return $events[0][1];
    }

    /**
     * 创建房间并可选配置（muc#owner 标准表单）。
     *
     * @return array join payload
     */
    public function createRoom($room, $nick = null, array $config = array(), $timeout = null)
    {
        $result = $this->join($room, $nick, null, $timeout);
        if (!empty($config)) {
            $this->configureRoom($room, $config, $timeout);
        }
        return $result;
    }

    /**
     * 提交房主配置表单；调用者必须是房主。
     *
     * @return XMLObj IQ result
     */
    public function configureRoom($room, array $config = array(), $timeout = null)
    {
        $roomJid = $this->roomJid($room);
        $fields = "<field var='FORM_TYPE'>"
            . '<value>http://jabber.org/protocol/muc#roomconfig</value>'
            . '</field>';
        foreach ($config as $key => $value) {
            if ($key === 'FORM_TYPE') {
                continue;
            }
            $fields .= "<field var='" . $this->xmlAttr($key) . "'><value>"
                . htmlspecialchars($this->stringValue($value))
                . '</value></field>';
        }

        $payload = "<query xmlns='" . self::NS_MUC_OWNER . "'>"
            . "<x xmlns='" . self::NS_X_DATA . "' type='submit'>" . $fields . '</x>'
            . '</query>';
        return $this->requestIq($roomJid, 'set', $payload, $timeout);
    }

    /**
     * 离开房间。
     *
     * @return bool 是否收到自己的 unavailable presence
     */
    public function leave($room, $nick = null, $timeout = null)
    {
        $roomJid = $this->roomJid($room);
        $nick = isset($this->rooms[$roomJid]['nick']) ? $this->rooms[$roomJid]['nick'] : $this->nick($nick);
        $timeout = $this->normalizeTimeout($timeout);

        $this->conn->send(
            "<presence to='" . $this->xmlAttr($roomJid) . '/' . $this->xmlAttr($nick)
            . "' type='unavailable'/>"
        );
        $events = $this->conn->processUntil('muc_left', $timeout);
        unset($this->rooms[$roomJid]);
        return (bool)$events;
    }

    public function sendGroupMessage($room, $body)
    {
        $roomJid = $this->roomJid($room);
        $body = htmlspecialchars((string)$body);
        return $this->conn->send(
            "<message to='" . $this->xmlAttr($roomJid) . "' type='groupchat'><body>" . $body . '</body></message>'
        );
    }

    public function sendPrivateMessage($room, $nick, $body)
    {
        $roomJid = $this->roomJid($room);
        $to = $roomJid . '/' . $nick;
        $body = htmlspecialchars((string)$body);
        return $this->conn->send(
            "<message to='" . $this->xmlAttr($to) . "' type='chat'><body>" . $body . '</body></message>'
        );
    }

    /**
     * 发送房间邀请（XEP-0045 mediated invitation）。
     */
    public function invite($room, $receiver, $reason = null)
    {
        $roomJid = $this->roomJid($room);
        $receiver = trim((string)$receiver);
        if ($receiver === '') {
            throw new \InvalidArgumentException('MUC invitee is required');
        }

        $invite = "<invite to='" . $this->xmlAttr($receiver) . "'>";
        if ($reason !== null && $reason !== '') {
            $invite .= '<reason>' . htmlspecialchars((string)$reason) . '</reason>';
        }
        $invite .= '</invite>';

        return $this->conn->send(
            "<message to='" . $this->xmlAttr($roomJid) . "'><x xmlns='" . self::NS_MUC_USER . "'>"
            . $invite . '</x></message>'
        );
    }

    /**
     * 拒绝房间邀请（XEP-0045 decline，发送给房间由服务端转发给邀请人）。
     */
    public function declineInvite($room, $inviter, $reason = null)
    {
        $roomJid = $this->roomJid($room);
        $inviter = trim((string)$inviter);
        if ($inviter === '') {
            throw new \InvalidArgumentException('MUC inviter is required');
        }

        $decline = "<decline to='" . $this->xmlAttr($inviter) . "'>";
        if ($reason !== null && $reason !== '') {
            $decline .= '<reason>' . htmlspecialchars((string)$reason) . '</reason>';
        }
        $decline .= '</decline>';

        return $this->conn->send(
            "<message to='" . $this->xmlAttr($roomJid) . "'><x xmlns='" . self::NS_MUC_USER . "'>"
            . $decline . '</x></message>'
        );
    }

    /**
     * 设置房间主题（groupchat subject）。
     */
    public function setTopic($room, $topic)
    {
        $roomJid = $this->roomJid($room);
        return $this->conn->send(
            "<message to='" . $this->xmlAttr($roomJid) . "' type='groupchat'><subject>"
            . htmlspecialchars((string)$topic) . '</subject></message>'
        );
    }

    public function isJoined($room)
    {
        $roomJid = $this->roomJid($room);
        return !empty($this->rooms[$roomJid]['joined']);
    }

    public function getOccupants($room)
    {
        $roomJid = $this->roomJid($room);
        return isset($this->rooms[$roomJid]['occupants']) ? $this->rooms[$roomJid]['occupants'] : array();
    }

    public function getRooms()
    {
        return $this->rooms;
    }

    /**
     * presence 事件处理：维护房间成员并派发 MUC 事件。
     *
     * @param array $payload
     */
    public function handlePresence($payload)
    {
        $xml = $payload['xml'] ?? null;
        if (!$xml instanceof XMLObj || empty($payload['from'])) {
            return;
        }

        $from = (string)$payload['from'];
        $parts = explode('/', $from, 2);
        $roomJid = $parts[0];
        $nick = $parts[1] ?? '';
        if (!$this->isMucRoom($roomJid)) {
            return;
        }

        $x = $xml->sub('x', null, self::NS_MUC_USER);
        if (!$x) {
            return;
        }

        $codes = $this->statusCodes($x);
        $event = $payload;
        $event['room'] = $roomJid;
        $event['nick'] = $nick;
        $event['codes'] = $codes;
        $event = array_merge($event, $this->mucItem($x));

        $type = $payload['type'] ?? 'available';
        if ($type === 'error') {
            $this->conn->event('muc_error', $event);
            return;
        }
        if ($type === 'unavailable') {
            $this->handleUnavailablePresence($event, $roomJid, $nick, $codes);
            return;
        }
        $this->handleAvailablePresence($event, $roomJid, $nick, $codes);
    }

    /**
     * message 事件处理：派发房间消息、主题、邀请、私聊事件。
     *
     * @param array $payload
     */
    public function handleMessage($payload)
    {
        $xml = $payload['xml'] ?? null;
        if (!$xml instanceof XMLObj || empty($payload['from'])) {
            return;
        }

        $from = (string)$payload['from'];
        $parts = explode('/', $from, 2);
        $roomJid = $parts[0];
        $nick = $parts[1] ?? '';
        if (!$this->isMucRoom($roomJid)) {
            return;
        }

        $type = $payload['type'] ?? 'chat';
        $event = $payload;
        $event['room'] = $roomJid;
        $event['nick'] = $nick;

        $x = $xml->sub('x', null, self::NS_MUC_USER);
        if ($x) {
            $invite = $x->sub('invite');
            if ($invite) {
                $reason = $invite->sub('reason');
                $password = $x->sub('password');
                $event['inviter'] = $invite->attrs['from'] ?? '';
                $event['invitee'] = $invite->attrs['to'] ?? '';
                $event['reason'] = $reason ? $reason->data : '';
                $event['password'] = $password ? $password->data : '';
                $this->conn->event('muc_invite', $event);
                return;
            }

            $decline = $x->sub('decline');
            if ($decline) {
                $reason = $decline->sub('reason');
                $event['invitee'] = $decline->attrs['from'] ?? '';
                $event['reason'] = $reason ? $reason->data : '';
                $this->conn->event('muc_invite_declined', $event);
                return;
            }
        }

        $subject = $xml->sub('subject');
        $event['subject'] = $subject ? $subject->data : '';
        if ($event['subject'] !== '') {
            $this->conn->event('muc_subject', $event);
        }

        if ($type === 'groupchat') {
            $event['body'] = $payload['body'] ?? false;
            if ($event['body'] !== false) {
                $this->conn->event('muc_message', $event);
            }
        } elseif ($type === 'chat' && $nick !== '') {
            $event['body'] = $payload['body'] ?? false;
            $this->conn->event('muc_private_message', $event);
        } elseif ($type === 'error') {
            $this->conn->event('muc_error', $event);
        }
    }

    /**
     * IQ 响应回调（由 addIdHandler 调用）。
     *
     * @param XMLObj $xml
     */
    public function handleIqResponse($xml)
    {
        $id = $xml->attrs['id'] ?? '';
        $this->conn->event('muc_iq_' . $id, $xml);
    }

    protected function requestIq($to, $type, $payload, $timeout = null)
    {
        $timeout = $this->normalizeTimeout($timeout);
        $id = 'muc_' . uniqid('', true);
        $this->conn->addIdHandler($id, 'handleIqResponse', $this);
        $this->conn->send("<iq type='$type' id='$id' to='" . $this->xmlAttr($to) . "'>$payload</iq>");
        $events = $this->conn->processUntil('muc_iq_' . $id, $timeout);
        $this->conn->removeIdHandler($id);

        if (!$events) {
            throw new Exception('MUC IQ timeout: ' . $to);
        }
        $response = $events[0][1];
        if (($response->attrs['type'] ?? '') === 'error') {
            $error = array('room' => $to, 'type' => 'iq_error', 'xml' => $response);
            $this->conn->event('muc_error', $error);
            throw new Exception('MUC IQ error (' . $to . '): ' . $this->describeError($error));
        }
        return $response;
    }

    protected function handleUnavailablePresence($event, $roomJid, $nick, array $codes)
    {
        if (isset($this->rooms[$roomJid]['occupants'][$nick])) {
            unset($this->rooms[$roomJid]['occupants'][$nick]);
        }

        $self = in_array('110', $codes, true);
        if ($self && isset($this->rooms[$roomJid])) {
            $this->rooms[$roomJid]['joined'] = false;
        }

        $removed = in_array('301', $codes, true)
            || in_array('307', $codes, true)
            || in_array('321', $codes, true);
        if ($self && $removed) {
            $this->conn->event('muc_removed', $event);
            return;
        }

        $this->conn->event($self ? 'muc_left' : 'muc_occupant_left', $event);
    }

    protected function handleAvailablePresence($event, $roomJid, $nick, array $codes)
    {
        if (!isset($this->rooms[$roomJid])) {
            $this->rooms[$roomJid] = array(
                'nick' => $nick,
                'joined' => false,
                'occupants' => array(),
            );
        }
        if (in_array('210', $codes, true) && $this->rooms[$roomJid]['nick'] !== $nick) {
            unset($this->rooms[$roomJid]['occupants'][$this->rooms[$roomJid]['nick']]);
            $this->rooms[$roomJid]['nick'] = $nick;
        }

        $isNew = !isset($this->rooms[$roomJid]['occupants'][$nick]);
        $this->rooms[$roomJid]['occupants'][$nick] = $event;

        if (in_array('110', $codes, true)) {
            $this->rooms[$roomJid]['joined'] = true;
            $this->rooms[$roomJid]['nick'] = $nick;
            $event['created'] = in_array('201', $codes, true);
            $this->conn->event('muc_joined', $event);
            return;
        }

        $this->conn->event($isNew ? 'muc_occupant_joined' : 'muc_occupant_presence', $event);
    }

    protected function statusCodes(XMLObj $x)
    {
        $codes = array();
        if (!is_array($x->subs)) {
            return $codes;
        }
        foreach ($x->subs as $sub) {
            if ($sub->name === 'status' && isset($sub->attrs['code'])) {
                $codes[] = (string)$sub->attrs['code'];
            }
        }
        return $codes;
    }

    protected function mucItem(XMLObj $x)
    {
        $item = $x->sub('item');
        if (!$item) {
            return array();
        }
        return array(
            'affiliation' => $item->attrs['affiliation'] ?? null,
            'role' => $item->attrs['role'] ?? null,
            'jid' => $item->attrs['jid'] ?? null,
            'newnick' => $item->attrs['nick'] ?? null,
        );
    }

    protected function isMucRoom($roomJid)
    {
        $suffix = '@' . $this->service;
        return substr($roomJid, -strlen($suffix)) === $suffix;
    }

    public function roomJid($room)
    {
        $room = trim((string)$room);
        if ($room === '') {
            throw new \InvalidArgumentException('MUC room is required');
        }
        if (strpos($room, '@') !== false) {
            return $room;
        }
        return $room . '@' . $this->service;
    }

    protected function nick($nick)
    {
        $nick = ($nick === null || $nick === '') ? $this->conn->user : $nick;
        $nick = trim((string)$nick);
        if ($nick === '') {
            throw new \InvalidArgumentException('MUC nick is required');
        }
        return $nick;
    }

    protected function normalizeTimeout($timeout)
    {
        return $timeout === null ? $this->timeout : (int)$timeout;
    }

    protected function stringValue($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return $value === null ? '' : (string)$value;
    }

    protected function xmlAttr($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function describeError($payload)
    {
        $xml = $payload['xml'] ?? null;
        $error = $xml instanceof XMLObj ? $xml->sub('error') : null;
        return $error ? $error->toString() : 'unknown error';
    }
}
