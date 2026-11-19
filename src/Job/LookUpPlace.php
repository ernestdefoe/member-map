<?php

namespace ErnestDefoe\MemberMap\Job;

use ErnestDefoe\MemberMap\Geocoder;
use Flarum\Queue\AbstractJob;

/** One place search or lookup at the geocoder, off the member's request (Geocoder::lookUp). */
class LookUpPlace extends AbstractJob
{
    /** @param array<string, scalar> $query */
    public function __construct(
        private readonly string $key,
        private readonly string $endpoint,
        private readonly array $query,
    ) {
    }

    public function handle(Geocoder $geocoder): void
    {
        $geocoder->lookUp($this->key, $this->endpoint, $this->query);
    }
}
