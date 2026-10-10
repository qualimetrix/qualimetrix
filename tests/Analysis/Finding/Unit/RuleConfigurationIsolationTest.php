<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
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
        $authored = ResolvedOptionsFixture::authoredConfiguration(
            ['rules' => ['complexity.alpha' => ['enabled' => true]]],
            $metadata,
            only: ['complexity.alpha'],
            disabled: ['complexity.beta'],
            cliOptions: ['complexity.alpha' => ['enabled' => true]],
        );
        $registry->replace(ResolvedOptionsFixture::ready($authored, $metadata));
        $enablement = $registry->enablement();
        self::assertNotNull($enablement);
        self::assertSame(['complexity.alpha'], $enablement->filter()?->selectors);
        self::assertSame('disabled_rules[0]: complexity.beta', $enablement->decisionFor('complexity.beta')->statement);
        $registry->captureExcludedFindings();

        $registry->resetRuntimeState();

        self::assertSnapshotUnavailable($registry);
        self::assertNull($registry->enablement());
        self::assertFalse($registry->capturesExcludedFindings());
    }
    private static function assertSnapshotUnavailable(\Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface $registry): void
    {
        try {
            $registry->resolvedOptions();
            self::fail('The invocation must have no ready rule options.');
        } catch (LogicException $refusal) {
            self::assertSame('Rule options are unavailable before analysis preflight.', $refusal->getMessage());
        }
    }
}
