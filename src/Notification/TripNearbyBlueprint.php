<?php

namespace ErnestDefoe\MemberMap\Notification;

use ErnestDefoe\MemberMap\Trip;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * "Dana is visiting Chicago, Dec 3-7": to members who live near a trip. The
 * subject is the traveler (a User, which has an API resource: a subject
 * without one 500s every notification list); the trip rides in the data.
 */
class TripNearbyBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Trip $trip, public User $traveler)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->traveler;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->traveler;
    }

    public function getData(): mixed
    {
        return ['trip' => $this->trip->id, 'label' => $this->trip->label, 'starts' => $this->trip->starts_on->toDateString(), 'ends' => $this->trip->ends_on->toDateString()];
    }

    public static function getType(): string
    {
        return 'memberMapTripNearby';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
