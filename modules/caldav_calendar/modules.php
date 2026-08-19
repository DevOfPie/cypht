<?php

/**
 * Caldav calendar modules
 * @package modules
 * @subpackage caldav_calendar
 */

if (!defined('DEBUG_MODE')) { die(); }

require_once APP_PATH.'modules/caldav_calendar/hm-caldav.php';

/**
 * @subpackage caldav_calendar/handler
 */
class Hm_Handler_load_caldav_events extends Hm_Handler_Module {
    public function process() {
        $auths = $this->user_config->get('caldav_calendar_auth_setting', array());
        $events = array();
        foreach (config('caldav') as $name => $vals) {
            if (!array_key_exists($name, $auths) || !array_key_exists('user', $auths[$name])) {
                continue;
            }
            $pass = '';
            if (array_key_exists('pass', $auths[$name])) {
                $pass = $auths[$name]['pass'];
            }
            $caldav = new Hm_Caldav($name, $vals['server'], $auths[$name]['user'], $pass);
            $caldav->get_events();
            $events = array_merge($events, $caldav->events);
        }
        $this->out('caldav_events', $events);
    }
}

/**
 * @subpackage caldav_calendar/handler
 */
class Hm_Handler_load_caldav_settings extends Hm_Handler_Module {
    public function process() {
        $this->out('caldav_settings', config('caldav'));
        $this->out('caldav_auth', $this->user_config->get('caldav_calendar_auth_setting', array()));
    }
}

/**
 * @subpackage caldav_calendar/handler
 */
class Hm_Handler_process_caldav_auth_settings extends Hm_Handler_Module {
    public function process() {
        if (!array_key_exists('save_settings', $this->request->post)) {
            return;
        }
        $settings = $this->user_config->get('caldav_calendar_auth_setting', array());
        $users = array();
        $passwords = array();
        $results = $settings;
        if (array_key_exists('caldav_usernames', $this->request->post)) {
            $users = $this->request->post['caldav_usernames'];
        }
        if (array_key_exists('caldav_passwords', $this->request->post)) {
            $passwords = $this->request->post['caldav_passwords'];
        }
        if (empty($settings)) {
            $settings = array_fill_keys(array_keys($users), array());
        }
        foreach ($settings as $name => $vals) {
            if (array_key_exists($name, $users)) {
                $results[$name]['user'] = $users[$name];
            }
            if (array_key_exists($name, $passwords)) {
                $results[$name]['pass'] = $passwords[$name];
            }
        }
        if (count($results) > 0) {
            $new_settings = $this->get('new_user_settings');
            $new_settings['caldav_calendar_auth_setting'] = $results;
            $this->out('new_user_settings', $new_settings, false);
        }
    }
}

/**
 * Merge remote events into the store at output time.
 *
 * The calendar handlers write the store back to user settings whenever an
 * event is added or deleted. Merging here, after every handler has run, keeps
 * server side events out of that copy so they cannot be persisted locally and
 * go stale.
 *
 * @subpackage caldav_calendar/output
 */
class Hm_Output_caldav_events extends Hm_Output_Module {
    protected function output() {
        $cal_events = $this->get('cal_events');
        if (!is_object($cal_events)) {
            return;
        }
        $count = 0;
        foreach ($this->get('caldav_events', array()) as $event) {
            if ($cal_events->add($event)) {
                $count++;
            }
        }
        Hm_Debug::add(sprintf('CALDAV: %s events merged into the calendar', $count));
    }
}

/**
 * @subpackage caldav_calendar/output
 */
class Hm_Output_caldav_auth_settings extends Hm_Output_Module {
    protected function output() {
        $settings = $this->get('caldav_settings', array());
        $auths = $this->get('caldav_auth', array());
        if (count($settings) == 0) {
            return;
        }
        $res = '<tr><td data-target=".caldav_settings" colspan="2" class="settings_subtitle cursor-pointer border-bottom p-2">'.
            '<i class="bi bi-calendar-event-fill fs-5 me-2"></i>'.
            $this->trans('CalDav Calendars').'</td></tr>';
        foreach ($settings as $name => $vals) {
            $user = '';
            $pass = false;
            if (array_key_exists($name, $auths)) {
                $user = $auths[$name]['user'];
                if (array_key_exists('pass', $auths[$name]) && $auths[$name]['pass']) {
                    $pass = true;
                }
            }
            $res .= '<tr class="caldav_settings"><td>'.$this->html_safe($name).'</td><td>';
            $res .= '<input autocomplete="username" type="text" value="'.$this->html_safe($user).'" name="caldav_usernames['.$this->html_safe($name).']" ';
            $res .= 'placeholder="'.$this->trans('Username').'" class="form-control warn_on_paste" /> <input type="password" class="form-control warn_on_paste"';
            if ($pass) {
                $res .= 'disabled="disabled" placeholder="'.$this->trans('Password saved').'" ';
                $res .= 'name="caldav_passwords['.$this->html_safe($name).']" /> <input type="button" ';
                $res .= 'value="'.$this->trans('Unlock').'" class="caldav_password_change btn btn-primary" /></td></tr>';
            }
            else {
                $res .= 'autocomplete="new-password" placeholder="'.$this->trans('Password').'" ';
                $res .= 'name="caldav_passwords['.$this->html_safe($name).']" /></td></tr>';
            }
        }
        return $res;
    }
}
