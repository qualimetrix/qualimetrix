<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeyRecognition;

#[CoversClass(ComputedMetricEntryKeyRecognition::class)]
final class ComputedMetricEntryKeyRecognitionTest extends TestCase
{
    #[Test]
    public function itAcceptsEveryDeclaredEntryKeyWithoutRefusing(): void
    {
        ComputedMetricEntryKeyRecognition::refuseUnknownKeys([
            'formula' => 'm["size.loc"]',
            'formulas' => ['class' => 'm["size.loc"]'],
            'levels' => ['class'],
            'description' => 'x',
            'inverted' => true,
            'warning' => 1,
            'error' => 1,
            'enabled' => true,
        ], 'computed.x');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itAcceptsANullFormulasBlockTheSameAsAnOmittedOne(): void
    {
        ComputedMetricEntryKeyRecognition::refuseUnknownKeys(['formulas' => null], 'computed.x');

        $this->addToAssertionCount(1);
    }

    /**
     * The walk prints the key exactly as it received it — it does not
     * camelize on its own way in. Camelizing already happened upstream, in
     * YAML normalization (`PRESERVE_IMMEDIATE_CHILDREN`), before an entry
     * ever reaches this method; a key built by hand, as this test does,
     * therefore stays in whatever spelling the test wrote.
     */
    #[Test]
    public function itRefusesAnUnknownEntryKeyPrintingItAsReceived(): void
    {
        try {
            ComputedMetricEntryKeyRecognition::refuseUnknownKeys(['bogusKey' => 1], 'computed.x');
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Option "bogusKey" is not an option of computed metric "computed.x"', $refusal->summary());
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame(['computed_metrics', 'computed.x', 'bogusKey'], $position->segments());
            self::assertSame('bogusKey', $position->written());
            self::assertTrue($position->isClosed());
        }
    }

    #[Test]
    public function itRefusesFormulasThatIsNotAMap(): void
    {
        try {
            ComputedMetricEntryKeyRecognition::refuseUnknownKeys(['formulas' => '1+1'], 'computed.x');
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame(['computed_metrics', 'computed.x', 'formulas'], $position->segments());
            self::assertFalse($position->isClosed());
        }
    }

    #[Test]
    public function itRefusesAFormulaKeyThatIsARealLevelWordNotReportedAt(): void
    {
        try {
            ComputedMetricEntryKeyRecognition::refuseUnknownKeys(['formulas' => ['callable' => 'x']], 'computed.x');
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('not one this capability reports at', $refusal->summary());
        }
    }

    #[Test]
    public function itRefusesAFormulaKeyThatIsNotALevelAtAll(): void
    {
        try {
            ComputedMetricEntryKeyRecognition::refuseUnknownKeys(['formulas' => ['clas' => 'x']], 'computed.x');
            self::fail('Expected a refusal.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('is not a level at all', $refusal->summary());
        }
    }
}
