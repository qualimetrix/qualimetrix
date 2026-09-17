<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html\Fixture;

/**
 * Mirrors the real HtmlTreeNode shape — its `$path` is a namespace path, not a
 * file path, so it must NOT be flagged. Acts as a negative regression fixture.
 */
final class HtmlTreeNodeFixture
{
    public string $path = '';
}
