<?php

if (!defined('DEBUG_MODE')) { die(); }

handler_source('caldav_calendar');
output_source('caldav_calendar');

/* calendar page */
add_handler('calendar', 'load_caldav_events', true, 'caldav_calendar', 'get_calendar_date', 'after');
add_output('calendar', 'caldav_events', true, 'caldav_calendar', 'calendar_content', 'before');

/* settings page */
add_handler('settings', 'load_caldav_settings', true, 'caldav_calendar', 'load_user_data', 'after');
add_handler('settings', 'process_caldav_auth_settings', true, 'caldav_calendar', 'save_user_settings', 'before');
add_output('settings', 'caldav_auth_settings', true, 'caldav_calendar', 'end_settings_form', 'before');

return array(
    'allowed_post' => array(
        'caldav_usernames' => array('filter' => FILTER_UNSAFE_RAW, 'flags'  => FILTER_FORCE_ARRAY),
        'caldav_passwords' => array('filter' => FILTER_UNSAFE_RAW, 'flags'  => FILTER_FORCE_ARRAY),
    )
);
