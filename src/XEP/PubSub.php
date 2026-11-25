<?php

namespace EasyXmpp\XEP;

use EasyXmpp\Util\XMLObj;
use EasyXmpp\XMPP;

/**
 * XEP-0060 Publish-Subscribe 最小实现。
 *
 * 只使用标准 IQ（pubsub / pubsub#owner）和 message 事件通知，
 * 不依赖服务端管理接口。PEP（XEP-0163）可把 service 设为用户 bare JID 使用。
 */
class PubSub
{
    public const NS_PUBSUB = 'http://jabber.org/protocol/pubsub';
    public const NS_PUBSUB_EVENT = 'http://jabber.org/protocol/pubsub#event';
    public const NS_PUBSUB_OWNER = 'http://jabber.org/protocol/pubsub#owner';
    public const NS_X_DATA = 'jabber:x:data';
    public const NS_DISCO_INFO = 'http://jabber.org/protocol/disco#info';

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

    public function __construct(XMPP $conn, $service, $timeout = 10)
    {
        if (!is_string($service) || trim($service) === '') {
            throw new \InvalidArgumentException('PubSub service is required');
        }
        $this->conn = $conn;
        $this->service = trim($service);
        $this->timeout = (int)$timeout;
        $conn->addEventHandler('message', 'handleMessage', $this);
    }

    /**
     * disco#info，查询服务能力。
     */
    public function discover($service = null)
    {
        $target = $service === null ? $this->service : $service;
        if (!is_string($target) || trim($target) === '') {
            throw new \InvalidArgumentException('PubSub discovery target is required');
        }
        return $this->conn->requestIq(
            $target,
            'get',
            "<query xmlns='" . self::NS_DISCO_INFO . "'/>",
            $this->timeout
        );
    }

    /**
     * @param XMLObj $result
     * @return string[]
     */
    public function features($result)
    {
        $features = array();
        $query = $result->sub('query');
        if ($query && is_array($query->subs)) {
            foreach ($query->subs as $sub) {
                if ($sub->name === 'feature' && isset($sub->attrs['var'])) {
                    $features[] = $sub->attrs['var'];
                }
            }
        }
        return $features;
    }

    /**
     * 创建节点；$node 为 null 时由服务端分配（instant node）。
     *
     * @return string|null 节点名
     */
    public function createNode($node = null, array $config = array())
    {
        $create = '<create';
        if ($node !== null && $node !== '') {
            $create .= " node='" . $this->xmlAttr($node) . "'";
        }
        $create .= '/>';
        if (!empty($config)) {
            $create .= '<configure>'
                . $this->dataForm('http://jabber.org/protocol/pubsub#node_config', $config)
                . '</configure>';
        }

        $result = $this->conn->requestIq(
            $this->service,
            'set',
            "<pubsub xmlns='" . self::NS_PUBSUB . "'>" . $create . '</pubsub>',
            $this->timeout
        );
        $created = $this->pubsubChild($result, 'create');
        return $created && isset($created->attrs['node']) ? $created->attrs['node'] : $node;
    }

    public function deleteNode($node)
    {
        $node = $this->requireNode($node);
        return $this->conn->requestIq(
            $this->service,
            'set',
            "<pubsub xmlns='" . self::NS_PUBSUB_OWNER . "'>"
            . "<delete node='" . $this->xmlAttr($node) . "'/></pubsub>",
            $this->timeout
        );
    }

    public function purgeNode($node)
    {
        $node = $this->requireNode($node);
        return $this->conn->requestIq(
            $this->service,
            'set',
            "<pubsub xmlns='" . self::NS_PUBSUB_OWNER . "'>"
            . "<purge node='" . $this->xmlAttr($node) . "'/></pubsub>",
            $this->timeout
        );
    }

    /**
     * 发布 item；$payloadXml 是调用方提供的合法 XML 片段。
     *
     * @return string|null item id
     */
    public function publish($node, $itemId = null, $payloadXml = null, array $options = array())
    {
        $node = $this->requireNode($node);
        $item = '<item';
        if ($itemId !== null && $itemId !== '') {
            $item .= " id='" . $this->xmlAttr($itemId) . "'";
        }
        if ($payloadXml === null || $payloadXml === '') {
            $item .= '/>';
        } else {
            $item .= '>' . $payloadXml . '</item>';
        }

        $inner = "<publish node='" . $this->xmlAttr($node) . "'>" . $item . '</publish>';
        if (!empty($options)) {
            $inner .= '<publish-options>'
                . $this->dataForm('http://jabber.org/protocol/pubsub#publish-options', $options)
                . '</publish-options>';
        }

        $result = $this->conn->requestIq(
            $this->service,
            'set',
            "<pubsub xmlns='" . self::NS_PUBSUB . "'>" . $inner . '</pubsub>',
            $this->timeout
        );
        $publish = $this->pubsubChild($result, 'publish');
        $item = $publish ? $publish->sub('item') : null;
        return $item ? ($item->attrs['id'] ?? $itemId) : $itemId;
    }

    public function retract($node, $itemId)
    {
        $node = $this->requireNode($node);
        $itemId = trim((string)$itemId);
        if ($itemId === '') {
            throw new \InvalidArgumentException('PubSub item id is required');
        }
        return $this->conn->requestIq(
            $this->service,
            'set',
            "<pubsub xmlns='" . self::NS_PUBSUB . "'>"
            . "<retract node='" . $this->xmlAttr($node) . "'>"
            . "<item id='" . $this->xmlAttr($itemId) . "'/></retract></pubsub>",
            $this->timeout
        );
    }

    /**
     * @return array subscription attributes
     */
    public function subscribe($node, $jid = null)
    {
        $node = $this->requireNode($node);
        $attrs = "node='" . $this->xmlAttr($node) . "'";
        if ($jid !== null && $jid !== '') {
            $attrs .= " jid='" . $this->xmlAttr($jid) . "'";
        }

        $result = $this->conn->requestIq(
            $this->service,
            'set',
            "<pubsub xmlns='" . self::NS_PUBSUB . "'><subscribe " . $attrs . '/></pubsub>',
            $this->timeout
        );
        $subscription = $this->pubsubChild($result, 'subscription');
        return $subscription ? $subscription->attrs : array();
    }

    public function unsubscribe($node, $jid = null, $subid = null)
    {
        $node = $this->requireNode($node);
        $attrs = "node='" . $this->xmlAttr($node) . "'";
        if ($jid !== null && $jid !== '') {
            $attrs .= " jid='" . $this->xmlAttr($jid) . "'";
        }
        if ($subid !== null && $subid !== '') {
            $attrs .= " subid='" . $this->xmlAttr($subid) . "'";
        }
        return $this->conn->requestIq(
            $this->service,
            'set',
            "<pubsub xmlns='" . self::NS_PUBSUB . "'><unsubscribe " . $attrs . '/></pubsub>',
            $this->timeout
        );
    }

    /**
     * @return array[] items with id/payload/xml
     */
    public function getItems($node, $maxItems = null)
    {
        $node = $this->requireNode($node);
        $attrs = "node='" . $this->xmlAttr($node) . "'";
        if ($maxItems !== null) {
            $attrs .= " max_items='" . (int)$maxItems . "'";
        }
        $result = $this->conn->requestIq(
            $this->service,
            'get',
            "<pubsub xmlns='" . self::NS_PUBSUB . "'><items " . $attrs . '/></pubsub>',
            $this->timeout
        );
        $items = $this->pubsubChild($result, 'items');
        return $items ? $this->parseItems($items) : array();
    }

    public function getSubscriptions($node = null)
    {
        $attrs = '';
        if ($node !== null && $node !== '') {
            $attrs = " node='" . $this->xmlAttr($node) . "'";
        }
        $result = $this->conn->requestIq(
            $this->service,
            'get',
            "<pubsub xmlns='" . self::NS_PUBSUB . "'><subscriptions" . $attrs . '/></pubsub>',
            $this->timeout
        );
        return $this->withNode($this->parseListAttributes($result, 'subscriptions', 'subscription'), $node);
    }

    public function getAffiliations($node = null)
    {
        $attrs = '';
        if ($node !== null && $node !== '') {
            $attrs = " node='" . $this->xmlAttr($node) . "'";
        }
        $result = $this->conn->requestIq(
            $this->service,
            'get',
            "<pubsub xmlns='" . self::NS_PUBSUB . "'><affiliations" . $attrs . '/></pubsub>',
            $this->timeout
        );
        return $this->withNode($this->parseListAttributes($result, 'affiliations', 'affiliation'), $node);
    }

    public function getNodeConfig($node)
    {
        $node = $this->requireNode($node);
        return $this->conn->requestIq(
            $this->service,
            'get',
            "<pubsub xmlns='" . self::NS_PUBSUB_OWNER . "'>"
            . "<configure node='" . $this->xmlAttr($node) . "'/></pubsub>",
            $this->timeout
        );
    }

    public function setNodeConfig($node, array $config)
    {
        $node = $this->requireNode($node);
        $payload = "<pubsub xmlns='" . self::NS_PUBSUB_OWNER . "'>"
            . "<configure node='" . $this->xmlAttr($node) . "'>"
            . $this->dataForm('http://jabber.org/protocol/pubsub#node_config', $config)
            . '</configure></pubsub>';
        return $this->conn->requestIq($this->service, 'set', $payload, $this->timeout);
    }

    /**
     * message 事件处理：派发 pubsub_* 事件。
     *
     * @param array $payload
     */
    public function handleMessage($payload)
    {
        $xml = $payload['xml'] ?? null;
        if (!$xml instanceof XMLObj) {
            return;
        }
        $event = $xml->sub('event', null, self::NS_PUBSUB_EVENT);
        if (!$event || !is_array($event->subs)) {
            return;
        }
        foreach ($event->subs as $sub) {
            $this->handleEventElement($sub);
        }
    }

    protected function handleEventElement(XMLObj $sub)
    {
        switch ($sub->name) {
            case 'items':
                $node = $sub->attrs['node'] ?? '';
                $items = $this->parseItems($sub);
                $this->conn->event('pubsub_items', array(
                    'node' => $node,
                    'items' => $items,
                    'xml' => $sub,
                ));
                foreach ($items as $item) {
                    $item['node'] = $node;
                    $this->conn->event('pubsub_item', $item);
                }
                break;
            case 'delete':
                $ids = array();
                if (is_array($sub->subs)) {
                    foreach ($sub->subs as $child) {
                        if ($child->name === 'item' && isset($child->attrs['id'])) {
                            $ids[] = $child->attrs['id'];
                        }
                    }
                }
                $this->conn->event('pubsub_retract', array(
                    'node' => $sub->attrs['node'] ?? '',
                    'ids' => $ids,
                    'xml' => $sub,
                ));
                break;
            case 'purge':
                $this->conn->event('pubsub_purge', array(
                    'node' => $sub->attrs['node'] ?? '',
                    'xml' => $sub,
                ));
                break;
            case 'configuration':
                $this->conn->event('pubsub_config', array(
                    'node' => $sub->attrs['node'] ?? '',
                    'xml' => $sub,
                ));
                break;
            case 'subscription':
                $event = $sub->attrs;
                $event['xml'] = $sub;
                $this->conn->event('pubsub_subscription', $event);
                break;
            case 'affiliations':
                $affiliations = array();
                if (is_array($sub->subs)) {
                    foreach ($sub->subs as $child) {
                        if ($child->name === 'affiliation') {
                            $affiliations[] = $child->attrs;
                        }
                    }
                }
                $this->conn->event('pubsub_affiliations', array(
                    'affiliations' => $affiliations,
                    'xml' => $sub,
                ));
                break;
        }
    }

    protected function parseItems(XMLObj $itemsElement)
    {
        $items = array();
        if (!is_array($itemsElement->subs)) {
            return $items;
        }
        foreach ($itemsElement->subs as $item) {
            if ($item->name !== 'item') {
                continue;
            }
            $payload = null;
            if (is_array($item->subs) && isset($item->subs[0])) {
                $payload = $item->subs[0];
            }
            $items[] = array(
                'id' => $item->attrs['id'] ?? null,
                'publisher' => $item->attrs['publisher'] ?? null,
                'payload' => $payload,
                'xml' => $item,
            );
        }
        return $items;
    }

    protected function parseListAttributes($result, $containerName, $childName)
    {
        $out = array();
        $container = $this->pubsubChild($result, $containerName);
        if (!$container || !is_array($container->subs)) {
            return $out;
        }
        foreach ($container->subs as $child) {
            if ($child->name === $childName) {
                $out[] = $child->attrs;
            }
        }
        return $out;
    }

    protected function pubsubChild($result, $name)
    {
        $pubsub = $result->sub('pubsub');
        return $pubsub ? $pubsub->sub($name) : null;
    }

    protected function withNode(array $list, $node)
    {
        if ($node === null || $node === '') {
            return $list;
        }
        foreach ($list as $i => $entry) {
            if (!isset($entry['node'])) {
                $list[$i]['node'] = $node;
            }
        }
        return $list;
    }

    protected function dataForm($formType, array $fields)
    {
        $xml = "<x xmlns='" . self::NS_X_DATA . "' type='submit'>"
            . "<field var='FORM_TYPE'><value>" . htmlspecialchars($formType) . '</value></field>';
        foreach ($fields as $key => $value) {
            if ($key === 'FORM_TYPE') {
                continue;
            }
            $xml .= "<field var='" . $this->xmlAttr($key) . "'><value>"
                . htmlspecialchars($this->stringValue($value)) . '</value></field>';
        }
        return $xml . '</x>';
    }

    protected function requireNode($node)
    {
        $node = trim((string)$node);
        if ($node === '') {
            throw new \InvalidArgumentException('PubSub node is required');
        }
        return $node;
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
}
