<?php

namespace ErnestDefoe\MemberMap\Api;

use Flarum\User\Exception\PermissionDeniedException;
use Psr\Http\Message\ServerRequestInterface;

/** Search engines and other bots never get the map's data, even where guests may see it. */
final class Crawlers
{
    private const PATTERN = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|quora link|outbrain|pinterest|vkshare|w3c_validator|whatsapp|scrapy|gptbot|ccbot|claudebot|perplexity|bytespider|petalbot|yandex|baidu|duckduck|applebot/i';

    /** @throws PermissionDeniedException for a known crawler */
    public static function refuse(ServerRequestInterface $request): void
    {
        if (preg_match(self::PATTERN, $request->getHeaderLine('User-Agent'))) {
            throw new PermissionDeniedException();
        }
    }
}
