<?php

namespace ErnestDefoe\MemberMap\Widget;

use Ernestdefoe\PageBuilder\Block\BlockInterface;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Where we are" as a Page Builder block. Registered from extend.php only
 * when Page Builder is installed (class_exists), so this file is never loaded
 * without its interface. The block draws itself in the browser from the same
 * cached stats endpoint as the other widget hosts, so it resolves nothing here.
 */
class WhereWeAreBlock implements BlockInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function type(): string
    {
        return 'member-map-where-we-are';
    }

    public function name(): string
    {
        return $this->translator->trans('ernestdefoe-member-map.forum.widget.title');
    }

    public function icon(): string
    {
        return 'fas fa-earth-americas';
    }

    public function category(): string
    {
        return 'forum';
    }

    public function defaultSettings(): array
    {
        return [];
    }

    public function settingsSchema(): array
    {
        return [];
    }

    public function resolve(array $settings, User $actor): array
    {
        return [];
    }
}
