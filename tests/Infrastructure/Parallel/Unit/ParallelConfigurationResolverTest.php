<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\Configuration\ParallelConfigurationResolver;

final class ParallelConfigurationResolverTest extends TestCase
{
    #[Test]
    public function itUsesTheLastWorkerContribution(): void
    {
        $configuration = (new ParallelConfigurationResolver())->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['parallel.workers' => 2]],
            ['source' => 'cli', 'values' => ['parallel.workers' => 0]],
        ], AbsolutePath::fromString('/project')));

        self::assertSame(0, $configuration->workers);
    }

    #[Test]
    public function itRejectsNegativeWorkerCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ParallelConfigurationResolver())->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['parallel.workers' => -1]],
        ], AbsolutePath::fromString('/project')));
    }
}
