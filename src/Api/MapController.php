<?php

namespace ErnestDefoe\MemberMap\Api;

use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\MemberQuery;
use ErnestDefoe\MemberMap\Settings;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/member-map: every member on the map, as lean rows.
 *
 *   {"members": [[id, lat, lng, displayName, slug, avatarUrl|null], ...], "me": {...}|null}
 *
 * One query for the markers (MemberQuery). Display names, slugs and avatars
 * come from the loaded rows, so nothing is queried per member. `me` is the
 * viewer's own row, found among the markers when they are listed.
 */
class MapController implements RequestHandlerInterface
{
    public function __construct(
        private readonly MemberQuery $members,
        private readonly SlugManager $slugs,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $table = (new Location())->getTable();

        $users = $this->members->listable($actor)
            ->select('users.*', "$table.lat as mm_lat", "$table.lng as mm_lng", "$table.label as mm_label", "$table.precision as mm_precision", "$table.show_on_profile as mm_profile")
            ->orderByDesc("$table.updated_at")
            ->limit(MemberQuery::MAX)
            ->get();

        $slugs = $this->slugs->forResource(User::class);
        $members = [];
        $me = null;

        foreach ($users as $user) {
            $members[] = [(int) $user->id, (float) $user->mm_lat, (float) $user->mm_lng, $user->display_name, $slugs->toSlug($user), $user->avatar_url];

            if ($user->id === $actor->id) {
                $me = self::own((float) $user->mm_lat, (float) $user->mm_lng, (string) $user->mm_label, (string) $user->mm_precision, (bool) $user->mm_profile, true);
            }
        }

        // Saved, but not shown (no longer allowed to be listed): still theirs to see and remove.
        if (! $me && ! $actor->isGuest() && ($own = Location::find($actor->id))) {
            $me = self::own($own->lat, $own->lng, $own->label, $own->precision, $own->show_on_profile, false);
        }

        return new JsonResponse(['members' => $members, 'me' => $me]);
    }

    /** @return array<string, mixed> */
    public static function own(float $lat, float $lng, string $label, string $precision, bool $profile, bool $listed): array
    {
        return compact('lat', 'lng', 'label', 'precision', 'profile', 'listed');
    }
}
