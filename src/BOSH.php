<?php

namespace EasyXmpp;

use EasyXmpp\Util\Log;

class BOSH extends XMPP
{

    protected $rid;
    protected $jid;
    protected $sid;
    protected $http_server;
    protected $http_buffer = array();
    protected $session = false;

    public function connect($timeout = 30, $persistent = false, $sendinit = true)
    {
        $this->http_server = "http://{$this->host}:{$this->port}/bosh";
        $this->use_encryption = false;
        $session = 1;
        $wait = 10;
        $this->rid = 3001;
        $this->sid = null;
        if ($session) {
            $this->loadSession();
        }
        if (!$this->sid) {
            $body = $this->__buildBody();
            $body->addAttribute('hold', '1');
            $body->addAttribute('to', $this->host);
            $body->addAttribute('route', "xmpp:{$this->host}:{$this->port}");
            $body->addAttribute('secure', 'true');
            $body->addAttribute('xmpp:version', '1.0', 'urn:xmpp:xbosh');
            $body->addAttribute('ver', '1.6');
            $body->addAttribute('wait', strval($wait));
            $body->addAttribute('ack', '1');
            $buff = "<stream:stream xmlns='jabber:client' xmlns:stream='http://etherx.jabber.org/streams'>";
            xml_parse($this->parser, $buff, false);
            $response = $this->__sendBody($body);
            $rxml = new \SimpleXMLElement($response);
            $this->sid = $rxml['sid'];
        } else {
            $buff = "<stream:stream xmlns='jabber:client' xmlns:stream='http://etherx.jabber.org/streams'>";
            xml_parse($this->parser, $buff, false);
        }
    }

    public function loadSession()
    {
        if (isset($_SESSION['XMPPHP_BOSH_RID']))
            $this->rid = $_SESSION['XMPPHP_BOSH_RID'];
        if (isset($_SESSION['XMPPHP_BOSH_SID']))
            $this->sid = $_SESSION['XMPPHP_BOSH_SID'];
        if (isset($_SESSION['XMPPHP_BOSH_authed']))
            $this->authed = $_SESSION['XMPPHP_BOSH_authed'];
        if (isset($_SESSION['XMPPHP_BOSH_jid']))
            $this->jid = $_SESSION['XMPPHP_BOSH_jid'];
        if (isset($_SESSION['XMPPHP_BOSH_fulljid']))
            $this->fulljid = $_SESSION['XMPPHP_BOSH_fulljid'];
    }

    public function __buildBody($sub = null)
    {
        $xml = new \SimpleXMLElement("<body xmlns='http://jabber.org/protocol/httpbind' xmlns:xmpp='urn:xmpp:xbosh' />");
        $xml->addAttribute('content', 'text/xml; charset=utf-8');
        $xml->addAttribute('rid', $this->rid);
        $this->rid += 1;
        if ($this->sid)
            $xml->addAttribute('sid', $this->sid);
        #if($this->sid) $xml->addAttribute('xmlns', 'http://jabber.org/protocol/httpbind');
        $xml->addAttribute('xml:lang', 'en', 'xml');
        if ($sub) { // ok, so simplexml is lame
            $p = dom_import_simplexml($xml);
            $c = dom_import_simplexml($sub);
            $cn = $p->ownerDocument->importNode($c, true);
            $p->appendChild($cn);
            $xml = simplexml_import_dom($p);
        }
        return $xml;
    }

    public function __sendBody($body = null, $recv = true)
    {
        if (!$body) {
            $body = $this->__buildBody();
        }

        $ch = curl_init($this->http_server);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body->asXML());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $header = array('Accept-Encoding: gzip, deflate', 'Content-Type: text/xml; charset=utf-8');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        $output = '';
        if ($recv) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $output = curl_exec($ch);
            $this->http_buffer[] = $output;
        }
        curl_close($ch);
        return $output;
    }

    public function __process($maximum = 5)
    {
        if ($this->http_buffer) {
            $this->__parseBuffer();
        } else {
            $this->__sendBody();
            $this->__parseBuffer();
        }
    }

    public function __parseBuffer()
    {
        while ($this->http_buffer) {
            $idx = key($this->http_buffer);
            $buffer = $this->http_buffer[$idx];
            unset($this->http_buffer[$idx]);
            if ($buffer) {
                $xml = new \SimpleXMLElement($buffer);
                $children = $xml->xpath('child::node()');
                foreach ($children as $child) {
                    $buff = $child->asXML();
                    $this->log->log("RECV: $buff", Log::DEBUG);
                    xml_parse($this->parser, $buff, false);
                }
            }
        }
    }

    public function send($msg, $timeout = NULL)
    {
        $this->log->log("SEND: $msg", Log::DEBUG);
        $msg = new \SimpleXMLElement($msg);
        #$msg->addAttribute('xmlns', 'jabber:client');
        $this->__sendBody($this->__buildBody($msg), true);
        #$this->__parseBuffer();
    }

    public function reset()
    {
        $this->xml_depth = 0;
        unset($this->xmlobj);
        $this->xmlobj = array();
        $this->setupParser();
        #$this->send($this->stream_start);
        $body = $this->__buildBody();
        $body->addAttribute('to', $this->host);
        $body->addAttribute('xmpp:restart', 'true', 'urn:xmpp:xbosh');
        $buff = "<stream:stream xmlns='jabber:client' xmlns:stream='http://etherx.jabber.org/streams'>";
        $response = $this->__sendBody($body);
        $this->been_reset = true;
        xml_parse($this->parser, $buff, false);
    }

    public function saveSession()
    {
        $_SESSION['XMPPHP_BOSH_RID'] = (string)$this->rid;
        $_SESSION['XMPPHP_BOSH_SID'] = (string)$this->sid;
        $_SESSION['XMPPHP_BOSH_authed'] = (boolean)$this->authed;
        $_SESSION['XMPPHP_BOSH_jid'] = (string)$this->jid;
        $_SESSION['XMPPHP_BOSH_fulljid'] = (string)$this->fulljid;
    }
}