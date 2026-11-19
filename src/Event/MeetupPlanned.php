<?php

namespace ErnestDefoe\MemberMap\Event;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Meetup;
use Flarum\User\User;

/** A meetup and its discussion were just created. Calendar listens (Integrations\AddMeetupToCalendar). */
class MeetupPlanned
{
    public function __construct(
        public readonly Meetup $meetup,
        public readonly User $actor,
        public readonly string $title,
        public readonly string $details,
        public readonly Carbon $startsLocal,
    ) {
    }
}
