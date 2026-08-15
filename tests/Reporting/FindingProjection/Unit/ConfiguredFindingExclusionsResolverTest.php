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
            ['source' => 'preset', 'values' => ['exclude_paths' => ['vendor'], 'exclude_namespaces' => ['Legacy']]],
            ['source' => 'config', 'values' => ['exclude_paths' => ['vendor', 'build'], 'exclude_namespaces' => ['Generated']]],
        ], AbsolutePath::fromString('/project')));

        self::assertSame(['vendor', 'build'], $resolved->excludePaths);
        self::assertSame(['Legacy', 'Generated'], $resolved->excludeNamespaces);
    }
}
