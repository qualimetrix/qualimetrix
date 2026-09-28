<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\Configuration\ParallelConfigurationResolver;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

final class ParallelConfigurationResolverTest extends TestCase
{
    /** @param array<string, mixed> $values */
    #[Test]
    #[DataProvider('provideAllowedWorkerCounts')]
    public function itResolvesAllowedWorkerCounts(array $values, ?int $expected): void
    {
        $configuration = (new ParallelConfigurationResolver())->resolve(LayeredDocument::of([
            ['source' => 'config.yaml', 'values' => $values],
        ], AbsolutePath::fromString('/project')));

        self::assertSame($expected, $configuration->workers);
    }

    /** @return iterable<string, array{array<string, mixed>, ?int}> */
    public static function provideAllowedWorkerCounts(): iterable
    {
        yield 'absent setting' => [[], null];
        yield 'null' => [[ConfigSchema::PARALLEL_WORKERS => null], null];
        yield 'positive integer' => [[ConfigSchema::PARALLEL_WORKERS => 2], 2];
    }

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

    #[Test]
    public function itPreservesTheAuthorWhenRejectingAStringWorkerCount(): void
    {
        try {
            (new ParallelConfigurationResolver())->resolve(LayeredDocument::of([
                ['source' => 'config.yaml', 'values' => [ConfigSchema::PARALLEL_WORKERS => '0']],
            ], AbsolutePath::fromString('/project')));
            self::fail('String worker counts must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('config.yaml', $refusal->sources()[0]->locator());
        }
    }
}
