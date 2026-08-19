<?php

/**
 * Caldav modules
 * @package modules
 * @subpackage caldav_calendar
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * @subpackage caldav_calendar/lib
 */
class Hm_Caldav {

    public $events = array();
    private $src;
    private $url;
    private $user;
    private $pass;
    private $principal_url;
    private $calendar_url;
    private $principal_path = '//response/propstat/prop/current-user-principal/href';
    private $calendar_path = '//response/propstat/prop/calendar-home-set/href';
    private $cal_list_path = '//response/href';
    private $cal_detail_path = '//response/propstat/prop/calendar-data';
    private $api;

    public function __construct($src, $url, $user, $pass) {
        $this->user = $user;
        $this->src = $src;
        $this->pass = $pass;
        $this->url = $url;
        $this->api = new Hm_API_Curl('xml');
    }

    public function get_events() {
        if (!$this->discover()) {
            return;
        }
        $res = array();
        $count = 0;
        foreach ($this->xml_find($this->list_calendars(), $this->cal_list_path, true) as $url) {
            $url = $this->url_concat($url);
            if ($url == $this->calendar_url) {
                continue;
            }
            $count++;
            Hm_Debug::add(sprintf('CALDAV: Trying calendar url %s', $url));
            foreach ($this->xml_find($this->report($url), $this->cal_detail_path, true) as $cal) {
                foreach ($this->split_vevents($cal) as $vevent) {
                    $event = $this->convert_to_event($vevent);
                    if ($event) {
                        $event['src_url'] = $url;
                        $res[] = $event;
                    }
                }
            }
        }
        Hm_Debug::add(sprintf('CALDAV: %s calendar urls found', $count));
        $this->events = $res;
    }

    /**
     * A calendar object may hold VTIMEZONE and VALARM components alongside the
     * event. Hm_ICal parses properties into one flat list, so those would
     * shadow the event's own DTSTART/DESCRIPTION. Isolate each VEVENT and wrap
     * it in a minimal VCALENDAR the parser will accept.
     */
    private function split_vevents($cal) {
        $res = array();
        if (!preg_match_all('/^BEGIN:VEVENT\r?\n(.*?)^END:VEVENT/ms', $cal, $matches)) {
            return $res;
        }
        foreach ($matches[1] as $body) {
            $body = preg_replace('/^BEGIN:VALARM\r?\n.*?^END:VALARM\r?\n?/ms', '', $body);
            $res[] = "BEGIN:VCALENDAR\nVERSION:2.0\n".trim($body)."\nEND:VCALENDAR";
        }
        return $res;
    }

    private function convert_to_event($vevent) {
        $parser = new Hm_ICal();
        if (!$parser->import($vevent)) {
            return false;
        }
        $ts = $this->event_timestamp($parser->fld_val('dtstart', false, false, true));
        if (!$ts) {
            Hm_Debug::add('CALDAV: Skipping event with no usable start date');
            return false;
        }
        $title = $parser->fld_val('summary', false, '');
        return array(
            'source' => $this->src,
            'type' => 'caldav',
            'title' => is_string($title) && trim($title) ? $title : '(no title)',
            'description' => $parser->fld_val('description', false, ''),
            'date' => $ts,
            'repeat_interval' => $this->repeat_interval($parser->fld_val('rrule', false, ''))
        );
    }

    /**
     * Hm_ICal hands back DTSTART already run through date_parse_from_format.
     * All day events (VALUE=DATE) parse a date with no time, so default the
     * clock fields rather than dropping the event.
     */
    private function event_timestamp($flds) {
        if (!is_array($flds) || count($flds) == 0) {
            return false;
        }
        $fld = $flds[0];
        if (!array_key_exists('values', $fld) || !is_array($fld['values'])) {
            return false;
        }
        $vals = $fld['values'];
        foreach (array('year', 'month', 'day') as $part) {
            if (!array_key_exists($part, $vals) || !is_numeric($vals[$part])) {
                return false;
            }
        }
        $time = array();
        foreach (array('hour', 'minute', 'second') as $part) {
            $time[] = (array_key_exists($part, $vals) && is_numeric($vals[$part])) ? $vals[$part] : 0;
        }
        $stamp = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $vals['year'], $vals['month'],
            $vals['day'], $time[0], $time[1], $time[2]);
        try {
            $date = new DateTime($stamp, $this->timezone($fld));
        }
        catch (Exception $oops) {
            Hm_Debug::add(sprintf('CALDAV: Could not build a date from %s', $stamp));
            return false;
        }
        return $date->getTimestamp();
    }

    /**
     * A TZID that this PHP build does not know, or a floating time, both fall
     * back to the site timezone.
     */
    private function timezone($fld) {
        if (array_key_exists('tzid', $fld) && is_string($fld['tzid'])) {
            if (in_array($fld['tzid'], timezone_identifiers_list(), true) || $fld['tzid'] == 'UTC') {
                return new DateTimeZone($fld['tzid']);
            }
            Hm_Debug::add(sprintf('CALDAV: Unknown TZID %s, using the site timezone', $fld['tzid']));
        }
        return new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Cypht repeats an event forever once an interval is set. Only map an
     * RRULE that actually runs forever; anything bounded by UNTIL or COUNT is
     * shown as a single event instead of an endless one.
     */
    private function repeat_interval($rrule) {
        if (!is_string($rrule) || !trim($rrule)) {
            return '';
        }
        $rrule = mb_strtoupper($rrule);
        if (mb_strpos($rrule, 'UNTIL=') !== false || mb_strpos($rrule, 'COUNT=') !== false) {
            return '';
        }
        if (mb_strpos($rrule, 'INTERVAL=') !== false && !preg_match('/INTERVAL=1(;|$)/', $rrule)) {
            return '';
        }
        $map = array('DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year');
        foreach ($map as $freq => $interval) {
            if (mb_strpos($rrule, 'FREQ='.$freq) !== false) {
                return $interval;
            }
        }
        return '';
    }

    private function discover() {
        $path = $this->xml_find($this->principal_discover(), $this->principal_path);
        if ($path === false) {
            Hm_Debug::add('CALDAV: No principal path discovered');
            return false;
        }
        Hm_Debug::add(sprintf('CALDAV: Found %s principal path', $path));
        $this->principal_url = $this->url_concat($path);
        $calendar_path = $this->xml_find($this->calendar_discover(), $this->calendar_path);
        if ($calendar_path === false) {
            Hm_Debug::add('CALDAV: No calendar path discovered');
            return false;
        }
        Hm_Debug::add(sprintf('CALDAV: Found %s calendar path', $calendar_path), 'info');
        $this->calendar_url = $this->url_concat($calendar_path);
        return true;
    }

    private function parse_xml($xml) {
        if (mb_substr((string) $this->api->last_status, 0, 1) != '2') {
            Hm_Debug::add(sprintf('ERRUnable to access CalDav server (%d)', $this->api->last_status));
            return false;
        }
        $xml = preg_replace("/<[a-zA-Z]+:/Um", "<", $xml);
        $xml = preg_replace("/<\/[a-zA-Z]+:/Um", "</", $xml);
        $xml = str_replace('xmlns=', 'ns=', $xml);
        try {
            $data = new SimpleXMLElement($xml);
            return $data;
        }
        catch (Exception $oops) {
            Hm_Msgs::add('Unable to access CalDav server', 'warning');
            Hm_Debug::add(sprintf('CALDAV: Could not parse XML: %s', $xml));
        }
        return false;
    }

    private function xml_find($xml, $path, $multi=false) {
        $data = $this->parse_xml($xml);
        if (!$data) {
            return $multi ? array() : false;
        }
        $res = array();
        foreach ($data->xpath($path) as $node) {
            if (!$multi) {
                return (string) $node;
            }
            $res[] = (string) $node;
        }
        if ($multi) {
            if (count($res) == 0) {
                Hm_Debug::add(sprintf('CALDAV: find for %s failed in xml: %s', $path, $xml));
            }
            return $res;
        }
        Hm_Debug::add(sprintf('CALDAV: find for %s failed in xml: %s', $path, $xml));
        return false;
    }

    private function url_concat($path) {
        $parsed = parse_url($this->url);
        $host = $parsed['host'];
        // If parsed URL contains a port, reappend it to host.
        if (!empty($parsed['port'])) {
            // Wrap IPv6 addresses in brackets before appending port
            if (strpos($host, ':') !== false) {
                $host = '[' . $host . ']';
            }
            $host .= ':' . $parsed['port'];
        }
        return sprintf('%s://%s/%s', $parsed['scheme'], $host, preg_replace('#^/#', '', $path));
    }

    private function auth_headers() {
        return array('Authorization: Basic '. base64_encode(sprintf('%s:%s', $this->user, $this->pass)));
    }

    private function calendar_discover() {
        $req_xml = '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>'.
            '<c:calendar-home-set /></d:prop></d:propfind>';
        return $this->api->command($this->principal_url, $this->auth_headers(), array(), $req_xml, 'PROPFIND');
    }

    private function principal_discover() {
        $req_xml = '<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal /></d:prop></d:propfind>';
        Hm_Debug::add(sprintf('CALDAV: Sending discover XML: %s', $req_xml), 'info');
        return $this->api->command($this->url, $this->auth_headers(), array(), $req_xml, 'PROPFIND');
    }

    private function list_calendars() {
        $headers = $this->auth_headers();
        $headers[] = 'Depth: 1';
        $req_xml = '<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop>'.
           '<d:resourcetype /><d:displayname /><cs:getctag /></d:prop></d:propfind>';
        Hm_Debug::add(sprintf('CALDAV: Sending calendar XML: %s', $req_xml), 'info');
        return $this->api->command($this->calendar_url, $headers, array(), $req_xml, 'PROPFIND');
    }

    private function report($url) {
        $headers = $this->auth_headers();
        $headers[] = 'Depth: 1';
        $req_xml = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'.
            '<d:prop><d:getetag /><c:calendar-data /></d:prop>'.
            '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT" />'.
            '</c:comp-filter></c:filter></c:calendar-query>';
        Hm_Debug::add(sprintf('CALDAV: Sending events XML: %s', $req_xml), 'info');
        return $this->api->command($url, $headers, array(), $req_xml, 'REPORT');
    }
}
