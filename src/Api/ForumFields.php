<?php

namespace ErnestDefoe\MemberMap\Api;

use ErnestDefoe\MemberMap\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/** The viewer's own Member Map permissions on the forum resource: already loaded, so no query. */
class ForumFields
{
    /** @return list<Schema\Boolean> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('memberMapCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW)),
            Schema\Boolean::make('memberMapCanList')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::LIST)),
            Schema\Boolean::make('memberMapCanTravel')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::TRAVEL)),
            Schema\Boolean::make('memberMapCanMeetup')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::MEETUP) && $context->getActor()->can('startDiscussion')),
        ];
    }
}
