<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Sarif;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Formatter\Sarif\SarifRuleCollector;

#[CoversClass(SarifRuleCollector::class)]
final class SarifRuleCollectorTest extends TestCase
{
    private SarifRuleCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new SarifRuleCollector();
    }

    // --- collectRules ---

    #[Test]
    public function itCollectsRulesFromEmptyViolations(): void
    {
        self::assertSame([], $this->collector->collectRules([]));
    }

    #[Test]
    public function itCollectsRulesWithCorrectStructure(): void
    {
        $violation = new Violation(
            location: new Location('src/Service.php', 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $rules = $this->collector->collectRules([$violation]);

        self::assertCount(1, $rules);
        $rule = $rules[0];

        self::assertSame('complexity.cyclomatic', $rule['id']);
        self::assertSame('Complexity Cyclomatic', $rule['name']);
        self::assertArrayHasKey('text', $rule['shortDescription']);
        self::assertSame('Code complexity exceeds threshold', $rule['shortDescription']['text']);
        self::assertArrayHasKey('text', $rule['fullDescription']);
        self::assertStringStartsWith('https://', $rule['helpUri']);
        self::assertSame('warning', $rule['defaultConfiguration']['level']);
    }

    #[Test]
    public function itDeduplicatesRulesByViolationCode(): void
    {
        $v1 = new Violation(
            location: new Location('a.php', 1),
            symbolPath: SymbolPath::forFile('a.php'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $v2 = new Violation(
            location: new Location('b.php', 5),
            symbolPath: SymbolPath::forFile('b.php'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Also too complex',
            severity: Severity::Warning,
        );

        $rules = $this->collector->collectRules([$v1, $v2]);

        self::assertCount(1, $rules);
    }

    #[Test]
    public function itCollectsMultipleDistinctRuleCodes(): void
    {
        $v1 = new Violation(
            location: new Location('a.php', 1),
            symbolPath: SymbolPath::forFile('a.php'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Complex',
            severity: Severity::Warning,
        );

        $v2 = new Violation(
            location: new Location('b.php', 1),
            symbolPath: SymbolPath::forFile('b.php'),
            ruleName: 'size.loc',
            violationCode: 'size.loc',
            message: 'Too long',
            severity: Severity::Error,
        );

        $rules = $this->collector->collectRules([$v1, $v2]);

        self::assertCount(2, $rules);
        $ids = array_column($rules, 'id');
        self::assertContains('complexity.cyclomatic', $ids);
        self::assertContains('size.loc', $ids);
    }

    #[Test]
    public function itPromotesRulesToErrorSeverity(): void
    {
        $warning = new Violation(
            location: new Location('a.php', 1),
            symbolPath: SymbolPath::forFile('a.php'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Complex',
            severity: Severity::Warning,
        );

        $error = new Violation(
            location: new Location('b.php', 1),
            symbolPath: SymbolPath::forFile('b.php'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Very complex',
            severity: Severity::Error,
        );

        $rules = $this->collector->collectRules([$warning, $error]);

        self::assertCount(1, $rules);
        self::assertSame('error', $rules[0]['defaultConfiguration']['level']);
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
    public function itReturnsDescriptionsForKnownRules(): void
    {
        self::assertSame('Code complexity exceeds threshold', $this->collector->getRuleDescription('complexity.cyclomatic'));
        self::assertSame('Code complexity exceeds threshold', $this->collector->getRuleDescription('complexity.cognitive'));
        self::assertSame('Maintainability index below threshold', $this->collector->getRuleDescription('maintainability.index'));
        self::assertSame('Circular dependency detected', $this->collector->getRuleDescription('architecture.circular-dependency'));
        self::assertSame('Duplicated code block detected', $this->collector->getRuleDescription('duplication.code-duplication'));
        self::assertSame('Too many parameters', $this->collector->getRuleDescription('size.long-parameter-list'));
        self::assertSame('Constructor has too many dependencies', $this->collector->getRuleDescription('code-smell.constructor-overinjection'));
    }

    #[Test]
    public function itFallsBackForUnknownRuleDescription(): void
    {
        $description = $this->collector->getRuleDescription('custom.my-rule');
        self::assertSame('Custom my rule', $description);
    }

    // --- getHelpUri ---

    #[Test]
    public function itReturnsHelpUriForKnownCategories(): void
    {
        self::assertStringContainsString('complexity/', $this->collector->getHelpUri('complexity.cyclomatic'));
        self::assertStringContainsString('coupling/', $this->collector->getHelpUri('coupling.cbo'));
        self::assertStringContainsString('cohesion/', $this->collector->getHelpUri('cohesion.tcc'));
        self::assertStringContainsString('code-smell/', $this->collector->getHelpUri('code-smell.empty-catch'));
        self::assertStringContainsString('security/', $this->collector->getHelpUri('security.sql-injection'));
        self::assertStringContainsString('architecture/', $this->collector->getHelpUri('duplication.code-duplication'));
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
}
