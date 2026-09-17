<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\FindingProjection\Unit;

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
            ['source' => 'preset', 'values' => ['suppress_paths' => ['vendor'], 'suppress_namespaces' => ['Legacy']]],
            ['source' => 'config', 'values' => ['suppress_paths' => ['vendor', 'build'], 'suppress_namespaces' => ['Generated']]],
        ], AbsolutePath::fromString('/project')));

        self::assertSame(['vendor', 'build'], $resolved->suppressPaths);
        self::assertSame(['Legacy', 'Generated'], $resolved->suppressNamespaces);
    }

    /**
     * A directory called `2024` is a lawful directory, and unquoted YAML hands
     * it over as an int. Refusing it here while `--suppress-path=2024` passes
     * would make the same name legal on one door and illegal on another.
     */
    #[Test]
    public function itReadsABareNumberAsTheDirectoryNameItIs(): void
    {
        $resolved = (new ConfiguredFindingExclusionsResolver())->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['suppress_paths' => [2024, 'src']]],
        ], AbsolutePath::fromString('/project')));

        self::assertSame(['2024', 'src'], $resolved->suppressPaths);
    }

    /**
     * A namespace segment cannot begin with a digit, so here the number is a
     * mistake rather than a name written without quotes.
     */
    #[Test]
    public function itStillRefusesABareNumberWhereANamespaceBelongs(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Invalid entry in "suppress_namespaces": every entry must be a string, got int.');

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
