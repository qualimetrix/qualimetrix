<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\Configuration\ParallelConfigurationResolver;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

final class ParallelConfigurationResolverTest extends TestCase
{
    #[Test]
    public function itUsesTheWinningWorkerValue(): void
    {
        $configuration = (new ParallelConfigurationResolver())->resolve(LayeredDocument::of([
            ['source' => 'config.yaml', 'values' => [ConfigSchema::PARALLEL_WORKERS => 2]],
            ['source' => 'cli', 'values' => [ConfigSchema::PARALLEL_WORKERS => 0]],
        ], AbsolutePath::fromString('/project')));

        self::assertSame(0, $configuration->workers);
    }

    #[Test]
    public function itRejectsNegativeWorkerCounts(): void
    {
        try {
            (new ParallelConfigurationResolver())->resolve(LayeredDocument::of([
                ['source' => 'config.yaml', 'values' => [ConfigSchema::PARALLEL_WORKERS => -1]],
            ], AbsolutePath::fromString('/project')));
            self::fail('Negative worker counts must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertCount(1, $refusal->sources());
            self::assertSame('config.yaml', $refusal->sources()[0]->locator());
        }
    }
}
