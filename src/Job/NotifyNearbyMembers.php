<?php

namespace ErnestDefoe\MemberMap\Job;

use ErnestDefoe\MemberMap\Trip;
use ErnestDefoe\MemberMap\Trips;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/** Tells members who live near a new trip (Trips::notify), off the request that saved it. */
class NotifyNearbyMembers extends AbstractJob
{
    public function __construct(private readonly int $tripId, private readonly int $travelerId)
    {
    }

    public function handle(Trips $trips): void
    {
        $trip = Trip::find($this->tripId);
        $traveler = User::find($this->travelerId);

        // Taken down (or the traveler deleted) before the job ran: nobody to tell.
        if ($trip && $traveler) {
            $trips->notify($trip, $traveler);
        }
    }
}
