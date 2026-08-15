<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(ConfigurationDocument::class)]
final class AnalysisConfigurationCacheDirResolutionTest extends TestCase
{
    #[Test]
    public function itKeepsTheInvocationWorkingDirectoryWithoutInterpretingOwnerValues(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'qmx.yaml', 'values' => ['cache.dir' => 'var/cache']],
        ], AbsolutePath::fromString('/project'));

        self::assertEquals(AbsolutePath::fromString('/project'), $document->workingDirectory());
        self::assertSame(['var/cache'], $document->contributions('cache.dir'));
    }

    #[Test]
    public function itReturnsNoContributionForAnAbsentOwnerKey(): void
    {
        $document = new ConfigurationDocument([], AbsolutePath::fromString('/project'));

        self::assertSame([], $document->contributions('cache.dir'));
    }
}
