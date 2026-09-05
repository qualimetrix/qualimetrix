<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\FindingProjection\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
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
}
