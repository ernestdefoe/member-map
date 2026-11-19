<?php

namespace ErnestDefoe\MemberMap\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/**
 * Swaps one of OUR integration classes for the version that uses an optional
 * extension. extend.php uses it only inside Extend\Conditional, so the class
 * that touches the other extension is never loaded while it is disabled.
 */
class Binding implements ExtenderInterface
{
    /**
     * @param class-string $abstract
     * @param class-string $concrete
     */
    public function __construct(private readonly string $abstract, private readonly string $concrete)
    {
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->bind($this->abstract, $this->concrete);
    }
}
