<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Core\Path\AbsolutePath;

final class FindingConfigurationResolverTest extends TestCase
{
    #[Test]
    public function itFoldsRuleSourcesWithThresholdModeEvictionAndSelectorSemantics(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'preset', 'values' => [
                'rules' => ['size.method-count' => ['warning' => 10, 'error' => 20]],
                'disabled_rules' => ['size'],
                'only_rules' => ['complexity'],
            ]],
            ['source' => 'qmx.yaml', 'values' => [
                'rules' => ['size.method-count' => ['threshold' => 15]],
                'disabled_rules' => ['security'],
                'only_rules' => ['design'],
            ]],
        ], AbsolutePath::fromString('/project'));

        $configuration = (new FindingConfigurationResolver())->resolve($document, new FindingCliOverrides());

        self::assertSame(['threshold' => 15], $configuration->ruleOptions->rules['size.method-count']);
        self::assertSame(['design'], $configuration->selection->only);
        self::assertSame(['size', 'security'], $configuration->selection->disabled);
    }
}
