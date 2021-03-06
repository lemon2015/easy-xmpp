<?php

namespace EasyXmpp;

use EasyXmpp\Exceptions\Exception;
use EasyXmpp\Stream\XMLStream;
use EasyXmpp\Util\Log;
use EasyXmpp\XEP\Roster;

/**
 * Class XMPP
 * @package EasyXmpp
 */
class XMPP extends XMLStream
{
    /**
     * @var string
     */
    public $user;
    /**
     * @var boolean
     */
    public $track_presence = true;
    /**
     * @var object
     */
    public $roster;
    /**
     * @var string
     */
    protected $password;
    /**
     * @var string
     */
    protected $resource;
    /**
     * @var string
     */
    protected $fulljid;
    /**
     * @var string
     */
    protected $basejid;
    /**
     * @var boolean
     */
    protected $authed = false;
    protected $session_started = false;
    /**
     * @var boolean
     */
    protected $auto_subscribe = false;
    /**
     * @var boolean
     */
    protected $use_encryption = true;
    /**
     * @var array supported auth mechanisms
     */
    protected $auth_mechanism_supported = array('PLAIN', 'DIGEST-MD5');
    /**
     * @var string default auth mechanism
     */
    protected $auth_mechanism_default = 'PLAIN';
    /**
     * @var string prefered auth mechanism
     */
    protected $auth_mechanism_preferred = 'DIGEST-MD5';

    /**
     * XMPP constructor.
     * @param $config
     */
    public function __construct($config)
    {
        $host = $config['host'];
        $port = $config['port'];
        $user = $config['user'];
        $password = $config['password'];
        $resource = $config['resource'];
        $server = $config['server'];
        $printlog = isset($config['printlog']) ? $config['printlog'] : false;
        $loglevel = isset($config['loglevel']) ? $config['loglevel'] : 7;
        $logfile = isset($config['logfile']) ? $config['logfile'] : "";
        parent::__construct($host, $port, $printlog, $loglevel, false, $logfile);

        $this->user = $user;
        $this->password = $password;
        $this->resource = $resource;
        if (!$server) {
            $server = $host;
        }
        $this->server = $server;
        $this->basejid = $this->user . '@' . $this->host;

        $this->roster = new Roster();
        $this->track_presence = true;

        $this->stream_start = '<stream:stream to="' . $host . '" xmlns:stream="http://etherx.jabber.org/streams" xmlns="jabber:client" version="1.0">';
        $this->stream_end = '</stream:stream>';
        $this->default_ns = 'jabber:client';

        $this->addXPathHandler('{http://etherx.jabber.org/streams}features', 'features_handler');
        $this->addXPathHandler('{urn:ietf:params:xml:ns:xmpp-sasl}success', 'sasl_success_handler');
        $this->addXPathHandler('{urn:ietf:params:xml:ns:xmpp-sasl}failure', 'sasl_failure_handler');
        $this->addXPathHandler('{urn:ietf:params:xml:ns:xmpp-tls}proceed', 'tls_proceed_handler');
        $this->addXPathHandler('{jabber:client}message', 'message_handler');
        $this->addXPathHandler('{jabber:client}presence', 'presence_handler');
        $this->addXPathHandler('iq/{jabber:iq:roster}query', 'roster_iq_handler');
        // For DIGEST-MD5 auth :
        $this->addXPathHandler('{urn:ietf:params:xml:ns:xmpp-sasl}challenge', 'sasl_challenge_handler');
    }

    /**
     * Turn encryption on/ff
     *
     * @param boolean $useEncryption
     */
    public function useEncryption($useEncryption = true)
    {
        $this->use_encryption = $useEncryption;
    }

    /**
     * Turn on auto-authorization of subscription requests.
     *
     * @param boolean $autoSubscribe
     */
    public function autoSubscribe($autoSubscribe = true)
    {
        $this->auto_subscribe = $autoSubscribe;
    }

    /**
     * Send XMPP Message
     *
     * @param string $to
     * @param string $body
     * @param string $type
     * @param string $subject
     */
    public function message($to, $body, $type = 'chat', $subject = null, $payload = null)
    {
        if (is_null($type)) {
            $type = 'chat';
        }

        $to = htmlspecialchars($to);
        $body = htmlspecialchars($body);
        $subject = htmlspecialchars($subject);

        $out = "<message from=\"{$this->fulljid}\" to=\"$to\" type='$type'>";
        if ($subject)
            $out .= "<subject>$subject</subject>";
        $out .= "<body>$body</body>";
        if ($payload)
            $out .= $payload;
        $out .= "</message>";

        $this->send($out);
    }

    /**
     * Set Presence
     *
     * @param string $status
     * @param string $show
     * @param string $to
     */
    public function presence($status = null, $show = 'available', $to = null, $type = 'available', $priority = 0)
    {
        if ($type == 'available')
            $type = '';
        $to = htmlspecialchars($to);
        $status = htmlspecialchars($status);
        if ($show == 'unavailable')
            $type = 'unavailable';

        $out = "<presence";
        if ($to)
            $out .= " to=\"$to\"";
        if ($type)
            $out .= " type='$type'";
        if ($show == 'available' and !$status) {
            $out .= "/>";
        } else {
            $out .= ">";
            if ($show != 'available')
                $out .= "<show>$show</show>";
            if ($status)
                $out .= "<status>$status</status>";
            if ($priority)
                $out .= "<priority>$priority</priority>";
            $out .= "</presence>";
        }

        $this->send($out);
    }

    /**
     * Send Auth request
     *
     * @param string $jid
     */
    public function subscribe($jid)
    {
        $this->send("<presence type='subscribe' to='{$jid}' from='{$this->fulljid}' />");
        #$this->send("<presence type='subscribed' to='{$jid}' from='{$this->fulljid}' />");
    }

    /**
     * Message handler
     *
     * @param string $xml
     */
    public function message_handler($xml)
    {
        if (isset($xml->attrs['type'])) {
            $payload['type'] = $xml->attrs['type'];
        } else {
            $payload['type'] = 'chat';
        }
        $payload['from'] = $xml->attrs['from'];
        $payload['body'] = $xml->sub('body')->data;
        $payload['xml'] = $xml;
        $this->log->log("Message: {$xml->sub('body')->data}", Log::INFO);
        $this->event('message', $payload);
    }

    /**
     * Presence handler
     *
     * @param string $xml
     */
    public function presence_handler($xml)
    {
        $payload['type'] = (isset($xml->attrs['type'])) ? $xml->attrs['type'] : 'available';
        $payload['show'] = (isset($xml->sub('show')->data)) ? $xml->sub('show')->data : $payload['type'];
        $payload['from'] = $xml->attrs['from'];
        $payload['status'] = (isset($xml->sub('status')->data)) ? $xml->sub('status')->data : '';
        $payload['priority'] = (isset($xml->sub('priority')->data)) ? intval($xml->sub('priority')->data) : 0;
        $payload['xml'] = $xml;
        if ($this->track_presence) {
            $this->roster->setPresence($payload['from'], $payload['priority'], $payload['show'], $payload['status']);
        }
        $this->log->log("Presence: {$payload['from']} [{$payload['show']}] {$payload['status']}", Log::INFO);
        if (array_key_exists('type', $xml->attrs) and $xml->attrs['type'] == 'subscribe') {
            if ($this->auto_subscribe) {
                $this->send("<presence type='subscribed' to='{$xml->attrs['from']}' from='{$this->fulljid}' />");
                $this->send("<presence type='subscribe' to='{$xml->attrs['from']}' from='{$this->fulljid}' />");
            }
            $this->event('subscription_requested', $payload);
        } else if (array_key_exists('type', $xml->attrs) and $xml->attrs['type'] == 'subscribed') {
            $this->event('subscription_accepted', $payload);
        } else {
            $this->event('presence', $payload);
        }
    }

    /**
     * Retrieves the roster
     *
     */
    public function getRoster()
    {
        $id = $this->getID();
        $this->send("<iq xmlns='jabber:client' type='get' id='$id'><query xmlns='jabber:iq:roster' /></iq>");
    }

    /**
     * Retrieves the vcard
     *
     */
    public function getVCard($jid = Null)
    {
        $id = $this->getID();
        $this->addIdHandler($id, 'vcard_get_handler');
        if ($jid) {
            $this->send("<iq type='get' id='$id' to='$jid'><vCard xmlns='vcard-temp' /></iq>");
        } else {
            $this->send("<iq type='get' id='$id'><vCard xmlns='vcard-temp' /></iq>");
        }
    }

    /**
     * Features handler
     *
     * @param string $xml
     */
    protected function features_handler($xml)
    {
        if ($xml->hasSub('starttls') and $this->use_encryption) {
            $this->send("<starttls xmlns='urn:ietf:params:xml:ns:xmpp-tls'><required /></starttls>");
        } else if ($xml->hasSub('bind') and $this->authed) {
            $id = $this->getId();
            $this->addIdHandler($id, 'resource_bind_handler');
            $this->send("<iq xmlns=\"jabber:client\" type=\"set\" id=\"$id\"><bind xmlns=\"urn:ietf:params:xml:ns:xmpp-bind\"><resource>{$this->resource}</resource></bind></iq>");
        } else {
            $this->log->log("Attempting Auth...");
            if ($this->password) {
                $mechanism = 'PLAIN'; // default;
                if ($xml->hasSub('mechanisms') && $xml->sub('mechanisms')->hasSub('mechanism')) {
                    // Get the list of all available auth mechanism that we can use
                    $available = array();
                    foreach ($xml->sub('mechanisms')->subs as $sub) {
                        if ($sub->name == 'mechanism') {
                            if (in_array($sub->data, $this->auth_mechanism_supported)) {
                                $available[$sub->data] = $sub->data;
                            }
                        }
                    }
                    if (isset($available[$this->auth_mechanism_preferred])) {
                        $mechanism = $this->auth_mechanism_preferred;
                    } else {
                        // use the first available
                        $mechanism = reset($available);
                    }
                    $this->log->log("Trying $mechanism (available : " . implode(',', $available) . ')');
                }
                switch ($mechanism) {
                    case 'PLAIN':
                        $this->send("<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='PLAIN'>" . base64_encode("\x00" . $this->user . "\x00" . $this->password) . "</auth>");
                        break;
                    case 'DIGEST-MD5':
                        $this->send("<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='DIGEST-MD5' />");
                        break;
                }
            } else {
                $this->send("<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='ANONYMOUS'/>");
            }
        }
    }

    /**
     * SASL success handler
     *
     * @param string $xml
     */
    protected function sasl_success_handler($xml)
    {
        $this->log->log("Auth success!");
        $this->authed = true;
        $this->reset();
    }

    /**
     * SASL feature handler
     *
     * @param string $xml
     */
    protected function sasl_failure_handler($xml)
    {
        $this->log->log("Auth failed!", Log::ERROR);
        $this->disconnect();

        throw new Exception('Auth failed!');
    }

    /**
     * Handle challenges for DIGEST-MD5 auth
     *
     * @param string $xml
     */
    protected function sasl_challenge_handler($xml)
    {
        // Decode and parse the challenge string
        // (may be something like foo="bar",foo2="bar2,bar3,bar4",foo3=bar5 )
        $challenge = base64_decode($xml->data);
        $vars = array();
        $matches = array();
        preg_match_all('/(\w+)=(?:"([^"]*)|([^,]*))/', $challenge, $matches);
        $res = array();
        foreach ($matches[1] as $k => $v) {
            $vars[$v] = (empty($matches[2][$k]) ? $matches[3][$k] : $matches[2][$k]);
        }
        if (isset($vars['nonce'])) {
            // First step
            $vars['cnonce'] = uniqid(mt_rand(), false);
            $vars['nc'] = '00000001';
            $vars['qop'] = 'auth'; // Force qop to auth
            if (!isset($vars['digest-uri']))
                $vars['digest-uri'] = 'xmpp/' . $this->server;

            // now, the magic...
            $a1 = sprintf('%s:%s:%s', $this->user, isset($vars['realm']) ? $vars['realm'] : '', $this->password);
            if ($vars['algorithm'] == 'md5-sess') {
                $a1 = pack('H32', md5($a1)) . ':' . $vars['nonce'] . ':' . $vars['cnonce'];
            }
            $a2 = "AUTHENTICATE:" . $vars['digest-uri'];
            $password = md5($a1) . ':' . $vars['nonce'] . ':' . $vars['nc'] . ':' . $vars['cnonce'] . ':' . $vars['qop'] . ':' . md5($a2);
            $password = md5($password);
            $response = sprintf('username="%s",realm="%s",nonce="%s",cnonce="%s",nc=%s,qop=%s,digest-uri="%s",response=%s,charset=utf-8',
                $this->user, isset($vars['realm']) ? $vars['realm'] : '', $vars['nonce'], $vars['cnonce'], $vars['nc'], $vars['qop'], $vars['digest-uri'], $password);

            // Send the response
            $response = base64_encode($response);
            $this->send("<response xmlns='urn:ietf:params:xml:ns:xmpp-sasl'>$response</response>");
        } else {
            if (isset($vars['rspauth'])) {
                // Second step
                $this->send("<response xmlns='urn:ietf:params:xml:ns:xmpp-sasl'/>");
            } else {
                $this->log->log("ERROR receiving challenge : " . $challenge, Log::ERROR);
            }
        }
    }

    /**
     * Resource bind handler
     *
     * @param string $xml
     */
    protected function resource_bind_handler($xml)
    {
        if ($xml->attrs['type'] == 'result') {
            $this->log->log("Bound to " . $xml->sub('bind')->sub('jid')->data);
            $this->fulljid = $xml->sub('bind')->sub('jid')->data;
            $jidarray = explode('/', $this->fulljid);
            $this->jid = $jidarray[0];
        }
        $id = $this->getId();
        $this->addIdHandler($id, 'session_start_handler');
        $this->send("<iq xmlns='jabber:client' type='set' id='$id'><session xmlns='urn:ietf:params:xml:ns:xmpp-session' /></iq>");
    }

    /**
     * Roster iq handler
     * Gets all packets matching XPath "iq/{jabber:iq:roster}query'
     *
     * @param string $xml
     */
    protected function roster_iq_handler($xml)
    {
        $status = "result";
        $xmlroster = $xml->sub('query');
        foreach ($xmlroster->subs as $item) {
            $groups = array();
            if ($item->name == 'item') {
                $jid = $item->attrs['jid']; //REQUIRED
                $name = $item->attrs['name']; //MAY
                $subscription = $item->attrs['subscription'];
                foreach ($item->subs as $subitem) {
                    if ($subitem->name == 'group') {
                        $groups[] = $subitem->data;
                    }
                }
                $contacts[] = array($jid, $subscription, $name, $groups); //Store for action if no errors happen
            } else {
                $status = "error";
            }
        }
        if ($status == "result") { //No errors, add contacts
            foreach ($contacts as $contact) {
                $this->roster->addContact($contact[0], $contact[1], $contact[2], $contact[3]);
            }
        }
        if ($xml->attrs['type'] == 'set') {
            $this->send("<iq type=\"reply\" id=\"{$xml->attrs['id']}\" to=\"{$xml->attrs['from']}\" />");
        }
    }

    /**
     * Session start handler
     *
     * @param string $xml
     */
    protected function session_start_handler($xml)
    {
        $this->log->log("Session started");
        $this->session_started = true;
        $this->event('session_start');
    }

    /**
     * TLS proceed handler
     *
     * @param string $xml
     */
    protected function tls_proceed_handler($xml)
    {
        $this->log->log("Starting TLS encryption");
        $res = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_SSLv23_CLIENT);
        if (!$res) {
            $this->log->log("TLS encryption failed");
        }
        $this->reset();
    }

    /**
     * VCard retrieval handler
     *
     * @param XML Object $xml
     */
    protected function vcard_get_handler($xml)
    {
        $vcard_array = array();
        $vcard = $xml->sub('vcard');
        // go through all of the sub elements and add them to the vcard array
        foreach ($vcard->subs as $sub) {
            if ($sub->subs) {
                $vcard_array[$sub->name] = array();
                foreach ($sub->subs as $sub_child) {
                    $vcard_array[$sub->name][$sub_child->name] = $sub_child->data;
                }
            } else {
                $vcard_array[$sub->name] = $sub->data;
            }
        }
        $vcard_array['from'] = $xml->attrs['from'];
        $this->event('vcard', $vcard_array);
    }

}