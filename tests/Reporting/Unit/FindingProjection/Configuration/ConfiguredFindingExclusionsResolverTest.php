<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\FindingProjection\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Reporting\FindingProjection\Configuration\ConfiguredFindingExclusionsResolver;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

final class ConfiguredFindingExclusionsResolverTest extends TestCase
{
    #[Test]
    public function itAccumulatesAndDeduplicatesConfiguredExclusions(): void
    {
        $resolved = (new ConfiguredFindingExclusionsResolver())->resolve(LayeredDocument::of([
            ['source' => 'preset', 'values' => ['suppress_paths' => [['subtree' => 'vendor']], 'suppress_namespaces' => [['subtree' => 'Legacy']]]],
            ['source' => 'config', 'values' => ['suppress_paths' => [['subtree' => 'vendor'], ['subtree' => 'build']], 'suppress_namespaces' => [['subtree' => 'Generated']]]],
        ], AbsolutePath::fromString('/project')));

        self::assertSame(['subtree:vendor', 'subtree:build'], array_map(static fn($pattern) => $pattern->definition->display(), $resolved->suppressPaths));
        self::assertSame(['subtree:Legacy', 'subtree:Generated'], array_map(static fn($pattern) => $pattern->definition->display(), $resolved->suppressNamespaces));
    }

    /**
     * The set keeps one element per spelling, yet every layer that wrote it is
     * a writer: a value the project repeats is the project's, whoever else
     * wrote it first.
     */
    #[Test]
    public function itNamesEveryLayerThatWroteAnAccumulatedValue(): void
    {
        $resolved = (new ConfiguredFindingExclusionsResolver())->resolve(LayeredDocument::of([
            ['source' => 'preset', 'values' => ['suppress_paths' => [['subtree' => 'vendor']], 'suppress_namespaces' => [['subtree' => 'Legacy']]]],
            ['source' => 'config', 'values' => ['suppress_paths' => [['subtree' => 'vendor'], ['subtree' => 'build']]]],
        ], AbsolutePath::fromString('/project')));

        self::assertSame('Written by preset "preset" and configuration file "config".', $resolved->writers->for(self::path('vendor'))?->sentence());
        self::assertSame('Written by configuration file "config".', $resolved->writers->for(self::path('build'))?->sentence());
        self::assertSame('Written by preset "preset".', $resolved->writers->for(self::namespace('Legacy'))?->sentence());
        self::assertNull($resolved->writers->for(self::namespace('vendor')), 'a path value names no namespace writer');
    }

    #[Test]
    public function itUnitesTheWritersOfAnEqualValueFromTheCommandLine(): void
    {
        $resolved = (new ConfiguredFindingExclusionsResolver())->resolve(LayeredDocument::of([
            ['source' => 'config', 'values' => ['suppress_paths' => [['subtree' => 'vendor']]]],
        ], AbsolutePath::fromString('/project')));
        $merged = $resolved->withCommandLine([self::path('vendor')], '--suppress-path', [self::namespace('Cli')], '--suppress-namespace');

        self::assertCount(1, $merged->suppressPaths);
        self::assertSame('Written by configuration file "config" and option --suppress-path.', $merged->writers->for(self::path('vendor'))?->sentence());
        self::assertSame('Written by option --suppress-namespace.', $merged->writers->for(self::namespace('Cli'))?->sentence());
    }

    /**
     * A directory called `2024` is a lawful directory, and unquoted YAML hands
     * it over as an int. Refusing it here while `--suppress-path=exact:2024`
     * passes keeps both doors explicit without losing the lawful name.
     */
    #[Test]
    public function itRefusesABareNumberWhereASelectorBelongs(): void
    {
        self::expectException(ConfigurationRefusal::class);
        (new ConfiguredFindingExclusionsResolver())->resolve(LayeredDocument::of([
            ['source' => 'config', 'values' => ['suppress_paths' => [2024]]],
        ], AbsolutePath::fromString('/project')));
    }

    /**
     * A namespace segment cannot begin with a digit, so here the number is a
     * mistake rather than a name written without quotes.
     */
    #[Test]
    public function itStillRefusesABareNumberWhereANamespaceBelongs(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"suppress_namespaces[0]" in configuration file "config" must be a map, got int.');

        (new ConfiguredFindingExclusionsResolver())->resolve(LayeredDocument::of([
            ['source' => 'config', 'values' => ['suppress_namespaces' => [2024]]],
        ], AbsolutePath::fromString('/project')));
    }

    #[Test]
    public function itRefusesAMapWhereAListOfEntriesBelongs(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"suppress_paths" in configuration file "config" must be a list, got a map.');

        (new ConfiguredFindingExclusionsResolver())->resolve(LayeredDocument::of([
            ['source' => 'config', 'values' => ['suppress_paths' => ['a' => 'vendor']]],
        ], AbsolutePath::fromString('/project')));
    }

    private static function path(string $value): PathPattern
    {
        return new PathPattern(SelectorDefinition::fromKindAndValue('subtree', $value));
    }

    private static function namespace(string $value): NamespacePattern
    {
        return new NamespacePattern(SelectorDefinition::fromKindAndValue('subtree', $value));
    }
}
