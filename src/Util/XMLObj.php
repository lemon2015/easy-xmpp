<?php

namespace EasyXmpp\Util;

class XMLObj
{

    /**
     * Tag name
     * @var string
     */
    public $name;
    /**
     * Namespace
     * @var string
     */
    public $ns;
    /**
     * Attributes
     * @var array
     */
    public $attrs = array();
    /**
     * Subs
     * @var array
     */
    public $subs = array();
    /**
     * Node data
     * @var string
     */
    public $data = '';

    /**
     * XMLObj constructor.
     * @param $name
     * @param string $ns
     * @param array $attrs
     * @param string $data
     */
    public function __construct($name, $ns = '', $attrs = array(), $data = '')
    {
        $this->name = strtolower($name);
        $this->ns = $ns;
        if (is_array($attrs) && count($attrs)) {
            foreach ($attrs as $key => $value) {
                $this->attrs[strtolower($key)] = $value;
            }
        }
        $this->data = $data;
    }

    /**
     * Dump this XML Object to output.
     * @param int $depth
     */
    public function printObj($depth = 0)
    {
        print str_repeat("\t", $depth) . $this->name . " " . $this->ns . ' ' . $this->data;
        print "\n";
        foreach ($this->subs as $sub) {
            $sub->printObj($depth + 1);
        }
    }

    /**
     * Return this XML Object in xml notation
     * @param string $str
     * @return string
     */
    public function toString($str = '')
    {
        $str .= "<{$this->name} xmlns='{$this->ns}' ";
        foreach ($this->attrs as $key => $value) {
            if ($key != 'xmlns') {
                $value = htmlspecialchars($value);
                $str .= "$key='$value' ";
            }
        }
        $str .= ">";
        foreach ($this->subs as $sub) {
            $str .= $sub->toString();
        }
        $body = htmlspecialchars($this->data);
        $str .= "$body</{$this->name}>";
        return $str;
    }

    /**
     * Has this XML Object the given sub
     * @param $name
     * @param null $ns
     * @return bool
     */
    public function hasSub($name, $ns = null)
    {
        foreach ($this->subs as $sub) {
            if (($name == "*" or $sub->name == $name) and ($ns == null or $sub->ns == $ns))
                return true;
        }
        return false;
    }

    /**
     * Return a sub
     * @param $name
     * @param null $attrs
     * @param null $ns
     * @return mixed
     */
    public function sub($name, $attrs = null, $ns = null)
    {
        #TODO attrs is ignored
        foreach ($this->subs as $sub) {
            if ($sub->name == $name and ($ns == null or $sub->ns == $ns)) {
                return $sub;
            }
        }
    }
}