<?php

namespace ErnestDefoe\MemberMap\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\Meetup;
use ErnestDefoe\MemberMap\Tests\fixtures\FakeGeocoder;
use Flarum\Discussion\Discussion;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class MeetupTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-member-map');

        $this->prepareDatabase([
            User::class => [$this->normalUser(), ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1]],
        ]);
    }

    private function fake(): void
    {
        $container = $this->app()->getContainer();
        $container->instance(Geocoder::class, $container->make(FakeGeocoder::class));
    }

    /** @return array{int, array<string, mixed>|null} */
    private function post(array $body): array
    {
        $response = $this->send($this->request('POST', '/api/member-map/meetups', ['authenticatedAs' => 2, 'json' => $body]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function a_meetup_starts_a_discussion_and_keeps_the_local_time()
    {
        $this->fake();
        $when = Carbon::now('America/Chicago')->addDays(10)->setTime(10, 0);

        [$status, $body] = $this->post(['title' => 'Coffee in Nashville', 'details' => 'By the river.', 'lat' => 36.16, 'lng' => -86.78, 'startsAt' => $when->format('Y-m-d\TH:i'), 'timezone' => 'America/Chicago']);

        $this->assertSame(201, $status, json_encode($body));
        $meetup = Meetup::query()->firstOrFail();
        $this->assertSame('America/Chicago', $meetup->timezone);
        $this->assertSame($when->copy()->utc()->format('Y-m-d H:i'), $meetup->starts_at->format('Y-m-d H:i'));

        $discussion = Discussion::query()->findOrFail($meetup->discussion_id);
        $this->assertSame('Coffee in Nashville', $discussion->title);
        $this->assertSame(2, $discussion->user_id);
        $this->assertStringContainsString('By the river.', $discussion->firstPost->content);

        $map = json_decode((string) $this->send($this->request('GET', '/api/member-map', ['authenticatedAs' => 3]))->getBody(), true);
        $this->assertSame($meetup->discussion_id, $map['meetups'][0][7]);
    }

    #[Test]
    public function a_meetup_in_the_past_or_without_a_name_is_refused()
    {
        $this->fake();

        $this->assertSame(422, $this->post(['title' => 'Old', 'lat' => 36.16, 'lng' => -86.78, 'startsAt' => '2020-01-01T10:00'])[0]);
        $this->assertSame(422, $this->post(['title' => 'x', 'lat' => 36.16, 'lng' => -86.78, 'startsAt' => Carbon::now()->addDay()->format('Y-m-d\TH:i')])[0]);
        $this->assertSame(0, Discussion::query()->count());
    }

    #[Test]
    public function only_the_host_or_a_moderator_takes_the_pin_down()
    {
        $this->fake();
        $this->post(['title' => 'Picnic', 'lat' => 36.16, 'lng' => -86.78, 'startsAt' => Carbon::now()->addDays(3)->format('Y-m-d\TH:i')]);
        $id = Meetup::query()->value('id');

        $this->assertSame(403, $this->send($this->request('DELETE', "/api/member-map/meetups/$id", ['authenticatedAs' => 3]))->getStatusCode());
        $this->assertSame(204, $this->send($this->request('DELETE', "/api/member-map/meetups/$id", ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertSame(0, Meetup::query()->count());
        $this->assertSame(1, Discussion::query()->count());
    }
}
