<?php

namespace EasyXmpp\XEP;

class Roster
{

    /**
     * Roster array, handles contacts and presence.  Indexed by jid.
     * Contains array with potentially two indexes 'contact' and 'presence'
     * @var array
     */
    protected $roster_array = array();

    /**
     * Roster constructor.
     * @param array $roster_array
     */
    public function __construct($roster_array = array())
    {
        if ($this->verifyRoster($roster_array)) {
            $this->roster_array = $roster_array; //Allow for prepopulation with existing roster
        } else {
            $this->roster_array = array();
        }
    }

    /**
     * Check that a given roster array is of a valid structure (empty is still valid)
     * @param $roster_array
     * @return bool
     */
    protected function verifyRoster($roster_array)
    {
        return true;
    }

    /**
     * @param $jid
     * @return mixed
     */
    public function getContact($jid)
    {
        if ($this->isContact($jid)) {
            return $this->roster_array[$jid]['contact'];
        }
    }

    /**
     * Discover if a contact exists in the roster via jid
     * @param $jid
     * @return bool
     */
    public function isContact($jid)
    {
        return (array_key_exists($jid, $this->roster_array));
    }

    /**
     * Set presence
     * @param string $presence
     * @param integer $priority
     * @param string $show
     * @param string $status
     */
    public function setPresence($presence, $priority, $show, $status)
    {
        list($jid, $resource) = explode("/", $presence);
        if ($show != 'unavailable') {
            if (!$this->isContact($jid)) {
                $this->addContact($jid, 'not-in-roster');
            }
            $resource = $resource ? $resource : '';
            $this->roster_array[$jid]['presence'][$resource] = array('priority' => $priority, 'show' => $show, 'status' => $status);
        } else { //Nuke unavailable resources to save memory
            unset($this->roster_array[$jid]['resource'][$resource]);
        }
    }

    /**
     * Add given contact to roster
     * @param string $jid
     * @param string $subscription
     * @param string $name
     * @param array $groups
     */
    public function addContact($jid, $subscription, $name = '', $groups = array())
    {
        $contact = array('jid' => $jid, 'subscription' => $subscription, 'name' => $name, 'groups' => $groups);
        if ($this->isContact($jid)) {
            $this->roster_array[$jid]['contact'] = $contact;
        } else {
            $this->roster_array[$jid] = array('contact' => $contact);
        }
    }

    /**
     * Return best presence for jid
     * @param $jid
     * @return array|mixed
     */
    public function getPresence($jid)
    {
        $split = split("/", $jid);
        $jid = $split[0];
        if ($this->isContact($jid)) {
            $current = array('resource' => '', 'active' => '', 'priority' => -129, 'show' => '', 'status' => ''); //Priorities can only be -128 = 127
            foreach ($this->roster_array[$jid]['presence'] as $resource => $presence) {
                //Highest available priority or just highest priority
                if ($presence['priority'] > $current['priority'] and (($presence['show'] == "chat" or $presence['show'] == "available") or ($current['show'] != "chat" or $current['show'] != "available"))) {
                    $current = $presence;
                    $current['resource'] = $resource;
                }
            }
            return $current;
        }
    }

    /**
     * Get roster
     * @return array
     */
    public function getRoster()
    {
        return $this->roster_array;
    }

}