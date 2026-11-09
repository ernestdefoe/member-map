<?php

namespace ErnestDefoe\MemberMap\Http;

use ErnestDefoe\MemberMap\Settings;
use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;

/**
 * The /map page as the server sends it: title, canonical URL, and noindex
 * unless the admin allows indexing. No member data: that needs JavaScript
 * and the view permission, and crawlers are refused it (Api\Crawlers).
 */
class MapPageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function __invoke(Document $document): Document
    {
        $document->title = (string) $this->translator->trans('ernestdefoe-member-map.forum.page.title');
        $document->canonicalUrl = $this->url->to('forum')->route('member-map');
        if (! $this->settings->get(Settings::INDEX)) {
            $document->meta['robots'] = 'noindex, nofollow';
        }

        return $document;
    }
}
