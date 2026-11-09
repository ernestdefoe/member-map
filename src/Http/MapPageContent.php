<?php

namespace ErnestDefoe\MemberMap\Http;

use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;

/** The /map page's title and canonical URL as the server sends it. No member data: that needs JavaScript and the view permission. */
class MapPageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document): Document
    {
        $document->title = (string) $this->translator->trans('ernestdefoe-member-map.forum.page.title');
        $document->canonicalUrl = $this->url->to('forum')->route('member-map');

        return $document;
    }
}
