<?php

use PHPUnit\Framework\TestCase;

class Hm_Test_Caldav_Calendar extends TestCase {

    private $caldav;

    public function setUp(): void {
        require_once APP_PATH.'modules/caldav_calendar/hm-caldav.php';
        date_default_timezone_set('UTC');
        $this->caldav = new Hm_Caldav('test', 'https://example.com', 'user@example.com', 'pass');
    }

    /* Reach the parsing path without a server to talk to */
    private function convert($ics) {
        $class = new ReflectionClass('Hm_Caldav');
        /* private since PHP 8.1 needs no setAccessible call */
        $split = $class->getMethod('split_vevents');
        $convert = $class->getMethod('convert_to_event');
        $res = array();
        foreach ($split->invoke($this->caldav, $ics) as $vevent) {
            $event = $convert->invoke($this->caldav, $vevent);
            if ($event) {
                $res[] = $event;
            }
        }
        return $res;
    }

    private function wrap($body) {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n".$body."END:VCALENDAR";
    }

    public function test_event_with_timezone_and_alarm() {
        /* The VTIMEZONE DTSTART and the VALARM DESCRIPTION must not win over
         * the event's own values */
        $ics = $this->wrap(
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nBEGIN:STANDARD\r\n".
            "DTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n".
            "END:STANDARD\r\nEND:VTIMEZONE\r\n".
            "BEGIN:VEVENT\r\nUID:a1\r\nDTSTART:20260819T143000Z\r\nDTEND:20260819T153000Z\r\n".
            "SUMMARY:Quarterly review\r\nDESCRIPTION:Bring the numbers\r\n".
            "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\nTRIGGER:-PT15M\r\n".
            "END:VALARM\r\nEND:VEVENT\r\n");
        $res = $this->convert($ics);
        $this->assertCount(1, $res);
        $this->assertEquals('Quarterly review', $res[0]['title']);
        $this->assertEquals('Bring the numbers', $res[0]['description']);
        $this->assertEquals('2026-08-19 14:30', gmdate('Y-m-d H:i', $res[0]['date']));
    }

    public function test_all_day_event() {
        $ics = $this->wrap("BEGIN:VEVENT\r\nUID:a2\r\nDTSTART;VALUE=DATE:20260901\r\n".
            "SUMMARY:Invoice day\r\nEND:VEVENT\r\n");
        $res = $this->convert($ics);
        $this->assertCount(1, $res);
        $this->assertEquals('2026-09-01 00:00', gmdate('Y-m-d H:i', $res[0]['date']));
    }

    public function test_tzid_is_converted() {
        $ics = $this->wrap("BEGIN:VEVENT\r\nUID:a3\r\nDTSTART;TZID=America/New_York:20260819T090000\r\n".
            "SUMMARY:Standup\r\nEND:VEVENT\r\n");
        $res = $this->convert($ics);
        $this->assertCount(1, $res);
        $this->assertEquals('2026-08-19 13:00', gmdate('Y-m-d H:i', $res[0]['date']));
    }

    public function test_unbounded_rrule_repeats() {
        $ics = $this->wrap("BEGIN:VEVENT\r\nUID:a4\r\nDTSTART:20260819T090000Z\r\n".
            "RRULE:FREQ=WEEKLY\r\nSUMMARY:Weekly sync\r\nEND:VEVENT\r\n");
        $res = $this->convert($ics);
        $this->assertEquals('week', $res[0]['repeat_interval']);
    }

    public function test_bounded_rrule_does_not_repeat() {
        /* Cypht repeats forever, so a bounded rule is shown once instead */
        foreach (array('FREQ=WEEKLY;UNTIL=20261231T000000Z', 'FREQ=DAILY;COUNT=5',
            'FREQ=DAILY;INTERVAL=3') as $rule) {
            $ics = $this->wrap("BEGIN:VEVENT\r\nUID:a5\r\nDTSTART:20260819T090000Z\r\n".
                "RRULE:".$rule."\r\nSUMMARY:Bounded\r\nEND:VEVENT\r\n");
            $res = $this->convert($ics);
            $this->assertEquals('', $res[0]['repeat_interval'], $rule);
        }
    }

    public function test_multiple_events_in_one_object() {
        $ics = $this->wrap("BEGIN:VEVENT\r\nUID:a6\r\nDTSTART:20260819T090000Z\r\nSUMMARY:First\r\n".
            "END:VEVENT\r\nBEGIN:VEVENT\r\nUID:a7\r\nDTSTART:20260820T090000Z\r\nSUMMARY:Second\r\n".
            "END:VEVENT\r\n");
        $res = $this->convert($ics);
        $this->assertCount(2, $res);
        $this->assertEquals('First', $res[0]['title']);
        $this->assertEquals('Second', $res[1]['title']);
    }

    public function test_folded_summary_is_unfolded() {
        $ics = $this->wrap("BEGIN:VEVENT\r\nUID:a8\r\nDTSTART:20260819T090000Z\r\n".
            "SUMMARY:A very long title that the server\r\n  wrapped\r\nEND:VEVENT\r\n");
        $res = $this->convert($ics);
        $this->assertEquals('A very long title that the server wrapped', $res[0]['title']);
    }

    public function test_event_without_start_is_skipped() {
        $ics = $this->wrap("BEGIN:VEVENT\r\nUID:a9\r\nSUMMARY:Broken\r\nEND:VEVENT\r\n");
        $this->assertCount(0, $this->convert($ics));
    }

    public function test_event_without_summary_gets_a_placeholder() {
        $ics = $this->wrap("BEGIN:VEVENT\r\nUID:a10\r\nDTSTART:20260819T090000Z\r\nEND:VEVENT\r\n");
        $res = $this->convert($ics);
        $this->assertCount(1, $res);
        $this->assertEquals('(no title)', $res[0]['title']);
    }

    public function test_no_events_in_object() {
        $this->assertCount(0, $this->convert($this->wrap("BEGIN:VTODO\r\nUID:a11\r\nEND:VTODO\r\n")));
    }
}
