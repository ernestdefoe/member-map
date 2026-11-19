<?php

namespace ErnestDefoe\MemberMap\Integrations;

use ErnestDefoe\MemberMap\Event\MeetupPlanned;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * With Calendar (ernestdefoe-calendar) and the member allowed to add events,
 * a meetup is an event there too, on the same discussion. Registered only
 * inside Extend\Conditional, so Calendar's model is only touched when it is on.
 */
class AddMeetupToCalendar
{
    public function __construct(private readonly LoggerInterface $log)
    {
    }

    public function handle(MeetupPlanned $event): void
    {
        if (! $event->actor->can('calendar.create')) {
            return;
        }

        try {
            $this->save($event);
        } catch (Throwable $e) {
            // The meetup stands without the calendar entry; never lose it over the add-on.
            $this->log->warning('[member-map] meetup '.$event->meetup->id.' not added to the calendar: '.$e->getMessage());
        }
    }

    private function save(MeetupPlanned $event): void
    {
        $starts = $event->startsLocal->copy()->utc();

        $calendarEvent = new \ErnestDefoe\Calendar\Event();
        $calendarEvent->user_id = $event->actor->id;
        $calendarEvent->discussion_id = $event->meetup->discussion_id;
        $calendarEvent->title = $event->title;
        $calendarEvent->slug = Str::slug($event->title).'-'.Str::lower(Str::random(5));
        $calendarEvent->description = $event->details ?: null;
        $calendarEvent->start_at = $starts;
        $calendarEvent->end_at = $starts->copy()->addHours(2);
        $calendarEvent->all_day = false;
        $calendarEvent->timezone = $event->meetup->timezone ?: 'UTC';
        $calendarEvent->location = $event->meetup->label;
        $calendarEvent->is_published = true;
        $calendarEvent->save();
    }
}
