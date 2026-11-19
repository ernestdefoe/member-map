<?php

namespace ErnestDefoe\MemberMap\Api;

use Exception;
use Flarum\Foundation\KnownError;

/**
 * An answer the member can act on. Its type reaches the browser as the error
 * `code` (Flarum's JSON:API error format), where it becomes a translated
 * sentence; extend.php (ErrorHandling) gives each type its HTTP status.
 */
class Problem extends Exception implements KnownError
{
    public function __construct(private readonly string $type)
    {
        parent::__construct($type);
    }

    public function getType(): string
    {
        return $this->type;
    }
}
