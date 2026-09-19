<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Health\Fixture;

final class BannedStringPathPropertyFixture
{
    public string $file = '';

    public ?string $filePath = null;

    public string|null $oldPath = null;

    public string $title = ''; // not a banned name — should NOT be flagged
}
