<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;

final class RuleConfigurationIsolationTest extends TestCase
{
    #[Test]
    public function itClearsEveryPerRunValueBeforeTheNextConfiguration(): void
    {
        $registry = new RuleOptionsRegistry();
        $registry->replace(new FindingConfiguration(
            new RuleOptionsDocument(['size.loc' => ['warning' => 10]]),
            new FindingCliOverrides(['size.loc' => ['error' => 20]]),
            new RuleSelection(['size'], ['security']),
        ));
        $registry->captureExcludedViolations();

        $registry->resetRuntimeState();

        self::assertSame([], $registry->configFileOptions());
        self::assertSame([], $registry->cliOptions());
        self::assertSame([], $registry->selection()->only);
        self::assertSame([], $registry->selection()->disabled);
        self::assertFalse($registry->capturesExcludedViolations());
    }
}
