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

/** With Tags enabled, meetups start in the admin's meetup tag, checked like any new discussion there. */
class MeetupTagsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-member-map');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'tags' => [
                ['id' => 1, 'name' => 'Meetups', 'slug' => 'meetups', 'position' => 0, 'is_restricted' => false],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => true],
            ],
        ]);
    }

    private function post(): array
    {
        $container = $this->app()->getContainer();
        $container->instance(Geocoder::class, $container->make(FakeGeocoder::class));

        $attributes = ['title' => 'Coffee', 'lat' => 36.16, 'lng' => -86.78, 'startsLocal' => Carbon::now()->addDays(3)->format('Y-m-d\TH:i')];
        $response = $this->send($this->request('POST', '/api/member-map-meetups', ['authenticatedAs' => 2, 'json' => ['data' => ['type' => 'member-map-meetups', 'attributes' => $attributes]]]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function without_a_meetup_tag_members_are_told_one_is_needed()
    {
        [$status, $body] = $this->post();

        $this->assertSame(422, $status);
        $this->assertSame('member_map_meetup_needs_tag', $body['errors'][0]['code']);
        $this->assertSame(0, Discussion::query()->count());
    }

    #[Test]
    public function a_restricted_meetup_tag_stays_restricted()
    {
        $this->setting('ernestdefoe-member-map.meetup_tag', 't2');

        [$status, $body] = $this->post();

        // The member cannot even see it, so it counts as no tag: refused either way, nothing created.
        $this->assertContains($status, [403, 422]);
        $this->assertSame(0, Meetup::query()->count());
    }

    #[Test]
    public function the_meetup_discussion_starts_in_the_meetup_tag()
    {
        $this->setting('ernestdefoe-member-map.meetup_tag', 't1');

        [$status, $body] = $this->post();

        $this->assertSame(201, $status, json_encode($body));
        $discussion = Discussion::query()->findOrFail(Meetup::query()->value('discussion_id'));
        $this->assertSame([1], $discussion->tags()->pluck('tags.id')->map(fn ($id) => (int) $id)->all());
    }
}
