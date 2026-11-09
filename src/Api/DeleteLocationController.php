<?php

namespace ErnestDefoe\MemberMap\Api;

use ErnestDefoe\MemberMap\Location;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/member-map/location: takes the viewer off the map. Allowed
 * without the listing permission: leaving must always work.
 */
class DeleteLocationController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        Location::query()->where('user_id', $actor->id)->delete();

        return new EmptyResponse(204);
    }
}
