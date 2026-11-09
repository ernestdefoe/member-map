<?php

namespace ErnestDefoe\MemberMap\Api;

use ErnestDefoe\MemberMap\Settings;
use Flarum\Extension\ExtensionManager;
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
 * Fetched only when a viewer picks that filter, so the map's own payload
 * stays one lean query. The ids only narrow what the viewer was already sent.
 */
class FilterController implements RequestHandlerInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);
        $params = $request->getQueryParams();

        if (isset($params['group']) && is_numeric($params['group'])) {
            $group = Group::whereVisibleTo($actor)->find((int) $params['group']);
            $ids = $group ? $this->db->table('group_user')->where('group_id', $group->id)->pluck('user_id') : [];
        } elseif (! empty($params['following']) && ! $actor->isGuest() && $this->extensions->isEnabled('ianm-follow-users')) {
            $ids = $this->db->table('user_followers')->where('user_id', $actor->id)->pluck('followed_user_id');
        } else {
            $ids = [];
        }

        return new JsonResponse(['ids' => array_map('intval', collect($ids)->all())]);
    }
}
