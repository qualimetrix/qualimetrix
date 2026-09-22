<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\FindingProjection\Configuration\ConfiguredFindingExclusionsResolver;

/**
 * A map here used to reach `array_push()` as named arguments and abort the run
 * with an internal error; a list carrying a non-string was dropped in silence.
 */
final class FindingExclusionShapeRefusalTest extends TestCase
{
    /**
     * Excludes the two cases {@see ConfiguredFindingExclusionsResolverTest}
     * already proves with a specific message: `suppress_paths` as a map, and a
     * non-string `suppress_namespaces` entry.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function provideUnexecutableValues(): iterable
    {
        yield 'suppress_paths as a scalar' => ['suppress_paths', 'src/Sub'];
        yield 'a path entry that cannot be a name at all' => ['suppress_paths', [true]];
        yield 'suppress_namespaces as a map' => ['suppress_namespaces', ['a' => 'Probe\\Sub']];
    }

    #[Test]
    #[DataProvider('provideUnexecutableValues')]
    public function itRefusesInsteadOfCrashingOrDroppingTheValue(string $key, mixed $value): void
    {
        $this->expectException(ConfigurationRefusal::class);

        self::resolve($key, $value);
    }

    #[Test]
    public function itStillAccumulatesLawfulLists(): void
    {
        $exclusions = self::resolve('suppress_paths', [
            ['subtree' => 'src/Sub'],
            ['exact' => 'src/Dup'],
        ]);

        self::assertSame(
            ['subtree:src/Sub', 'exact:src/Dup'],
            array_map(static fn($pattern): string => $pattern->definition->display(), $exclusions->suppressPaths),
        );
    }

    #[Test]
    public function itStillAcceptsAnEmptyList(): void
    {
        self::assertSame([], self::resolve('suppress_namespaces', [])->suppressNamespaces);
    }

    private static function resolve(
        string $key,
        mixed $value,
    ): \Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions {
        $document = new ConfigurationDocument(
            [['source' => 'config', 'values' => [$key => $value]]],
            AbsolutePath::fromString('/project'),
        );

        return (new ConfiguredFindingExclusionsResolver())->resolve($document);
    }
}
