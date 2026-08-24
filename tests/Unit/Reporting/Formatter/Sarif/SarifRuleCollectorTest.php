<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Sarif;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\Formatter\Sarif\SarifRuleCollector;

/**
 * The old version of this test asserted the collector's `match` table
 * against finding codes the product never emits (`code:
 * 'complexity.cyclomatic'` — the product emits `complexity.cyclomatic.callable`
 * / `.class`), so it validated the table against itself. This version drives
 * the collector against `complexity.cyclomatic.callable`, a code the real
 * container's {@see ChannelPresentationInterface} actually resolves — see
 * `docs/internal/plans/sarif-channel-descriptions.md`, package P4, for the
 * broader guard that sweeps every channel of the real universe.
 */
#[CoversClass(SarifRuleCollector::class)]
final class SarifRuleCollectorTest extends TestCase
{
    private SarifRuleCollector $collector;

    protected function setUp(): void
    {
        $presentation = (new ContainerFactory())->create()->get(ChannelPresentationInterface::class);
        \assert($presentation instanceof ChannelPresentationInterface);

        $this->collector = new SarifRuleCollector($presentation);
    }

    // --- collectRules ---

    #[Test]
    public function itCollectsRulesFromEmptyFindings(): void
    {
        self::assertSame([], $this->collector->collectRules([]));
    }

    #[Test]
    public function itCollectsRulesWithCorrectStructure(): void
    {
        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $rules = $this->collector->collectRules([$finding]);

        self::assertCount(1, $rules);
        $rule = $rules[0];

        self::assertSame('complexity.cyclomatic.callable', $rule['id']);
        self::assertSame('Complexity Cyclomatic Callable', $rule['name']);
        self::assertArrayHasKey('text', $rule['shortDescription']);
        self::assertSame('Checks cyclomatic complexity at method and class levels', $rule['shortDescription']['text']);
        self::assertArrayHasKey('text', $rule['fullDescription']);
        self::assertSame('https://qualimetrix.dev/rules/complexity/', $rule['helpUri']);
        self::assertSame('warning', $rule['defaultConfiguration']['level']);
    }

    #[Test]
    public function itDeduplicatesRulesByCode(): void
    {
        $v1 = self::finding(
            location: new Location(RelativePath::fromString('a.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('a.php')),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $v2 = self::finding(
            location: new Location(RelativePath::fromString('b.php'), 5),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('b.php')),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'Also too complex',
            severity: Severity::Warning,
        );

        $rules = $this->collector->collectRules([$v1, $v2]);

        self::assertCount(1, $rules);
    }

    #[Test]
    public function itCollectsMultipleDistinctRuleCodes(): void
    {
        $v1 = self::finding(
            location: new Location(RelativePath::fromString('a.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('a.php')),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'Complex',
            severity: Severity::Warning,
        );

        $v2 = self::finding(
            location: new Location(RelativePath::fromString('b.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('b.php')),
            ruleName: 'size.class-count',
            code: 'size.class-count',
            message: 'Too many classes',
            severity: Severity::Error,
        );

        $rules = $this->collector->collectRules([$v1, $v2]);

        self::assertCount(2, $rules);
        $ids = array_column($rules, 'id');
        self::assertContains('complexity.cyclomatic.callable', $ids);
        self::assertContains('size.class-count', $ids);
    }

    #[Test]
    public function itPromotesRulesToErrorSeverity(): void
    {
        $warning = self::finding(
            location: new Location(RelativePath::fromString('a.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('a.php')),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'Complex',
            severity: Severity::Warning,
        );

        $error = self::finding(
            location: new Location(RelativePath::fromString('b.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('b.php')),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'Very complex',
            severity: Severity::Error,
        );

        $rules = $this->collector->collectRules([$warning, $error]);

        self::assertCount(1, $rules);
        self::assertSame('error', $rules[0]['defaultConfiguration']['level']);
    }

    #[Test]
    public function itGivesSubCodesOfOneRuleDistinctIdsAndTheSameDescription(): void
    {
        $namespaceLevel = self::finding(
            location: new Location(RelativePath::fromString('a.php'), 1),
            symbolPath: SymbolPath::forNamespace('App'),
            ruleName: 'coupling.cbo',
            code: 'coupling.cbo.namespace',
            message: 'Too coupled',
            severity: Severity::Warning,
        );

        $classLevel = self::finding(
            location: new Location(RelativePath::fromString('a.php'), 1),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'coupling.cbo',
            code: 'coupling.cbo.class',
            message: 'Too coupled',
            severity: Severity::Warning,
        );

        $rules = $this->collector->collectRules([$namespaceLevel, $classLevel]);

        self::assertCount(2, $rules);
        $byId = array_column($rules, null, 'id');
        self::assertArrayHasKey('coupling.cbo.namespace', $byId);
        self::assertArrayHasKey('coupling.cbo.class', $byId);
        self::assertNotSame($byId['coupling.cbo.namespace']['id'], $byId['coupling.cbo.class']['id']);
        self::assertSame(
            $byId['coupling.cbo.namespace']['shortDescription']['text'],
            $byId['coupling.cbo.class']['shortDescription']['text'],
        );
    }

    // --- formatRuleName ---

    #[Test]
    public function itFormatsRuleNameConvertingDotSeparated(): void
    {
        self::assertSame('Complexity Cyclomatic', $this->collector->formatRuleName('complexity.cyclomatic'));
    }

    #[Test]
    public function itFormatsRuleNameConvertingKebabCase(): void
    {
        self::assertSame('Code Smell Long Parameter List', $this->collector->formatRuleName('code-smell.long-parameter-list'));
    }

    #[Test]
    public function itFormatsRuleNameHandlingSingleWord(): void
    {
        self::assertSame('Custom', $this->collector->formatRuleName('custom'));
    }

    // --- getRuleDescription ---

    #[Test]
    public function itReturnsDescriptionsForKnownChannelsFromTheRealPresentation(): void
    {
        self::assertSame(
            'Checks cyclomatic complexity at method and class levels',
            $this->collector->getRuleDescription('complexity.cyclomatic.callable'),
        );
        self::assertSame(
            'Detects circular dependencies between classes',
            $this->collector->getRuleDescription('architecture.circular-dependency'),
        );
        self::assertSame(
            'Detects duplicated code blocks',
            $this->collector->getRuleDescription('duplication.code-duplication'),
        );
        self::assertSame(
            'Checks number of constructor parameters (dependencies)',
            $this->collector->getRuleDescription('code-smell.constructor-overinjection'),
        );
    }

    #[Test]
    public function itFallsBackForACodeNoChannelCarries(): void
    {
        $description = $this->collector->getRuleDescription('custom.my-rule');
        self::assertSame('Custom my rule', $description);
    }

    /**
     * Demonstrates the fallback's own discriminating power (plan P4 DoD): an
     * empty `ComputedMetricDefinition::$description` resolves to `null` per
     * {@see ChannelPresentationInterface}'s own contract — indistinguishable
     * here from "no channel at all" — so the collector must fall back rather
     * than surface a blank string as if it were a legitimate answer.
     */
    #[Test]
    public function itFallsBackWhenThePresentationResolvesToNull(): void
    {
        $blank = self::createStub(ChannelPresentationInterface::class);
        $blank->method('presentationFor')->willReturn(null);

        $collector = new SarifRuleCollector($blank);

        self::assertSame('Health cohesion', $collector->getRuleDescription('health.cohesion'));
        self::assertSame(SarifRuleCollector::INFORMATION_URI, $collector->getHelpUri('health.cohesion'));
    }

    // --- getHelpUri ---

    #[Test]
    public function itReturnsHelpUriForKnownChannelsFromTheRealPresentation(): void
    {
        self::assertSame('https://qualimetrix.dev/rules/complexity/', $this->collector->getHelpUri('complexity.cyclomatic.callable'));
        self::assertSame('https://qualimetrix.dev/rules/coupling/', $this->collector->getHelpUri('coupling.cbo.class'));
        self::assertSame('https://qualimetrix.dev/rules/cohesion/', $this->collector->getHelpUri('cohesion.lcom'));
        self::assertSame('https://qualimetrix.dev/rules/code-smell/', $this->collector->getHelpUri('code-smell.empty-catch'));
        self::assertSame('https://qualimetrix.dev/rules/security/', $this->collector->getHelpUri('security.sql-injection'));
        // The historical defect this plan removes: `duplication.*` no longer
        // resolves to the `architecture/` page.
        self::assertSame('https://qualimetrix.dev/rules/duplication/', $this->collector->getHelpUri('duplication.code-duplication'));
    }

    #[Test]
    public function itFallsBackToRepositoryUrlForHelpUri(): void
    {
        self::assertSame(SarifRuleCollector::INFORMATION_URI, $this->collector->getHelpUri('unknown.rule'));
    }

    #[Test]
    public function itFallsBackHelpUriWhenNoDot(): void
    {
        self::assertSame(SarifRuleCollector::INFORMATION_URI, $this->collector->getHelpUri('norule'));
    }

    // --- mapLevel ---

    #[Test]
    public function itMapsErrorLevel(): void
    {
        self::assertSame('error', $this->collector->mapLevel(Severity::Error));
    }

    #[Test]
    public function itMapsWarningLevel(): void
    {
        self::assertSame('warning', $this->collector->mapLevel(Severity::Warning));
    }

    /**
     * Builds a finding fixture with an explicit declaration or aggregate
     * subject, preserving the production contract without hiding it behind a
     * legacy fallback.
     *
     * @param list<\Qualimetrix\Analysis\Finding\Contract\Location> $relatedLocations
     */
    private static function finding(
        \Qualimetrix\Analysis\Finding\Contract\Location $location,
        \Qualimetrix\Core\Symbol\SymbolPath $symbolPath,
        string $ruleName,
        string $code,
        string $message,
        \Qualimetrix\Analysis\Finding\Contract\Severity $severity,
        int|float|null $metricValue = null,
        array $relatedLocations = [],
        ?string $recommendation = null,
        int|float|null $threshold = null,
        ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null,
        ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null,
        ?\Qualimetrix\Analysis\Finding\Contract\AcceptedLevel $acceptedLevel = null,
        ?\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey $occurrenceKey = null,
        ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null,
    ): Finding {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File,
            \Qualimetrix\Core\Symbol\SymbolType::Namespace_,
            \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
        };

        return new Finding(
            location: $location,
            subject: $subject,
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            code: $code,
            message: $message,
            severity: $severity,
            metricValue: $metricValue,
            relatedLocations: $relatedLocations,
            recommendation: $recommendation,
            threshold: $threshold,
            dependencyTarget: $dependencyTarget,
            dependencyType: $dependencyType,
            acceptedLevel: $acceptedLevel,
            occurrenceKey: $occurrenceKey,
        );
    }
}
