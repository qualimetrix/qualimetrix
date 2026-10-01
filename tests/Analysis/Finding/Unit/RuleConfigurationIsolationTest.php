<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

final class RuleConfigurationIsolationTest extends TestCase
{
    #[Test]
    public function itClearsEveryPerRunValueBeforeTheNextConfiguration(): void
    {
        $registry = new RuleOptionsRegistry();
        $metadata = [
            new RuleMetadata('complexity.alpha', CodeSmellOptions::class, 'Alpha', [], false),
            new RuleMetadata('complexity.beta', CodeSmellOptions::class, 'Beta', [], false),
        ];
        $registry->replace(ResolvedOptionsFixture::ready(new FindingConfiguration(
            new RuleOptionsDocument(['complexity.alpha' => ['enabled' => true]]),
            new FindingCliOverrides(['complexity.alpha' => ['enabled' => true]]),
        ), $metadata, only: ['complexity.alpha'], disabled: ['complexity.beta']));
        $enablement = $registry->enablement();
        self::assertNotNull($enablement);
        self::assertSame(['complexity.alpha'], $enablement->filter()?->selectors);
        self::assertSame('disabled_rules[0]: complexity.beta', $enablement->decisionFor('complexity.beta')->statement);
        $registry->captureExcludedFindings();

        $registry->resetRuntimeState();

        self::assertSame([], $registry->configFileOptions());
        self::assertSame([], $registry->cliOptions());
        self::assertNull($registry->enablement());
        self::assertFalse($registry->capturesExcludedFindings());
    }
}
