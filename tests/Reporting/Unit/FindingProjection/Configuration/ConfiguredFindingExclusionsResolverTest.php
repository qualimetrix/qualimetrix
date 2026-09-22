<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\FindingProjection\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\FindingProjection\Configuration\ConfiguredFindingExclusionsResolver;

final class ConfiguredFindingExclusionsResolverTest extends TestCase
{
    #[Test]
    public function itAccumulatesAndDeduplicatesConfiguredExclusions(): void
    {
        $resolved = (new ConfiguredFindingExclusionsResolver())->resolve(new ConfigurationDocument([
            ['source' => 'preset', 'values' => ['suppress_paths' => [['subtree' => 'vendor']], 'suppress_namespaces' => [['subtree' => 'Legacy']]]],
            ['source' => 'config', 'values' => ['suppress_paths' => [['subtree' => 'vendor'], ['subtree' => 'build']], 'suppress_namespaces' => [['subtree' => 'Generated']]]],
        ], AbsolutePath::fromString('/project')));

        self::assertSame(['subtree:vendor', 'subtree:build'], array_map(static fn($pattern) => $pattern->definition->display(), $resolved->suppressPaths));
        self::assertSame(['subtree:Legacy', 'subtree:Generated'], array_map(static fn($pattern) => $pattern->definition->display(), $resolved->suppressNamespaces));
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
        (new ConfiguredFindingExclusionsResolver())->resolve(new ConfigurationDocument([
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
        self::expectExceptionMessage('Selector entries must be one-entry mappings');

        (new ConfiguredFindingExclusionsResolver())->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['suppress_namespaces' => [2024]]],
        ], AbsolutePath::fromString('/project')));
    }

    #[Test]
    public function itRefusesAMapWhereAListOfEntriesBelongs(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Invalid value for "suppress_paths": expected a list of entries, got a map.');

        (new ConfiguredFindingExclusionsResolver())->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['suppress_paths' => ['a' => 'vendor']]],
        ], AbsolutePath::fromString('/project')));
    }
}
