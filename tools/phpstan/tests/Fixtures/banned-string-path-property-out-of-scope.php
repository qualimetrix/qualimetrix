<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Fixture;

/**
 * Configuration namespace is explicitly excluded from the rule scope
 * (CLI/loader boundary classes may stay string-typed). No errors expected.
 */
final class OutOfScopeFixture
{
    public string $file = '';

    public ?string $filePath = null;
}
