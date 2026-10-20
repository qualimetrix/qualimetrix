<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\TestSupport\WorkingDirectory;

use PHPUnit\Framework\Attributes\Before;
use RuntimeException;

trait RunsFromRepositoryRoot
{
    #[Before]
    public function pinWorkingDirectoryToRepositoryRoot(): void
    {
        if (!chdir(\dirname(__DIR__, 3))) {
            throw new RuntimeException('Cannot enter the repository root for this test');
        }
    }
}
