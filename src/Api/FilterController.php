<?php

namespace ErnestDefoe\MemberMap\Api;

use ErnestDefoe\MemberMap\Integrations\Follows;
use ErnestDefoe\MemberMap\Settings;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/member-map/filter?group=4 | ?following=1  ->  {ids: [...]}
 *
 * A plain handler, not a resource: it answers with bare user ids that narrow
 * the markers the viewer already has, so the map's own payload stays one lean
 * query. Fetched only when a viewer picks that filter.
 */
class FilterController implements RequestHandlerInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Follows $follows,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);
        $params = $request->getQueryParams();
        $ids = [];

        if (isset($params['group']) && is_numeric($params['group'])) {
            $group = Group::whereVisibleTo($actor)->find((int) $params['group']);
            $ids = $group ? $this->db->table('group_user')->where('group_id', $group->id)->pluck('user_id')->all() : [];
        } elseif (! empty($params['following']) && ! $actor->isGuest()) {
            $ids = $this->follows->followedBy($actor);
        }

        return new JsonResponse(['ids' => array_map('intval', $ids)]);
    }
}
