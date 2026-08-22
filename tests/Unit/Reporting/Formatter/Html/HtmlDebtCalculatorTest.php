<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Html\HtmlDebtCalculator;
use Qualimetrix\Reporting\Formatter\Html\HtmlTreeNode;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(HtmlDebtCalculator::class)]
final class HtmlDebtCalculatorTest extends TestCase
{
    private HtmlDebtCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new HtmlDebtCalculator(
            new DebtCalculator(new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues())),
        );
    }

    #[Test]
    public function itComputesZeroDebtWithNoViolations(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $this->calculator->computeDebt([], ['App\\Service' => $node]);

        self::assertSame(0, $node->debtMinutes);
    }

    #[Test]
    public function itComputesDebtWithViolations(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'Service'), RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
            metricValue: 10,
        );

        $this->calculator->computeDebt(
            ['App\\Service' => [$violation]],
            ['App\\Service' => $node],
        );

        // complexity.cyclomatic = 30 minutes per RemediationTimeRegistry
        self::assertSame(30, $node->debtMinutes);
    }

    #[Test]
    public function itSkipsUnknownNodePathsWhenComputingDebt(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Other.php'), 10),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'Other'), RelativePath::fromString('src/Other.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App', 'Other'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $this->calculator->computeDebt(
            ['App\\Other' => [$violation]],
            ['App\\Service' => $node],
        );

        self::assertSame(0, $node->debtMinutes);
    }

    #[Test]
    public function itAggregatesBottomUpWithNoChildren(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');
        $node->violations = [
            ['subject' => 'declaration:class:s@f:0', 'ruleName' => 'r1', 'violationCode' => 'r1', 'message' => 'm', 'recommendation' => null, 'severity' => 'warning', 'metricValue' => 1, 'symbolPath' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 1],
            ['subject' => 'declaration:class:s@f:1', 'ruleName' => 'r2', 'violationCode' => 'r2', 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 2, 'symbolPath' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 2],
        ];
        $node->debtMinutes = 60;

        $total = $this->calculator->aggregateBottomUp($node);

        self::assertSame(2, $total);
        self::assertSame(2, $node->violationCountTotal);
        self::assertSame(60, $node->debtMinutes); // No children, debt unchanged
    }

    #[Test]
    public function itSumsChildViolationsAndDebtWhenAggregatingBottomUp(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');

        $childA = new HtmlTreeNode('A', 'App\\A', 'class');
        $childA->violations = [
            ['subject' => 'declaration:class:s@f:0', 'ruleName' => 'r1', 'violationCode' => 'r1', 'message' => 'm', 'recommendation' => null, 'severity' => 'warning', 'metricValue' => 1, 'symbolPath' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 1],
        ];
        $childA->debtMinutes = 30;

        $childB = new HtmlTreeNode('B', 'App\\B', 'class');
        $childB->violations = [
            ['subject' => 'declaration:class:s@f:1', 'ruleName' => 'r2', 'violationCode' => 'r2', 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 2, 'symbolPath' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 2],
            ['subject' => 'declaration:class:s@f:2', 'ruleName' => 'r3', 'violationCode' => 'r3', 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 3, 'symbolPath' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 3],
        ];
        $childB->debtMinutes = 45;

        $root->children = [$childA, $childB];

        $total = $this->calculator->aggregateBottomUp($root);

        self::assertSame(3, $total);
        self::assertSame(3, $root->violationCountTotal);
        self::assertSame(1, $childA->violationCountTotal);
        self::assertSame(2, $childB->violationCountTotal);

        // Root's own debt (0) + children debt (30 + 45)
        self::assertSame(75, $root->debtMinutes);
    }

    #[Test]
    public function itAggregatesBottomUpForDeepHierarchy(): void
    {
        // Root -> NS -> ClassA (1 violation, 20min debt)
        //                ClassB (2 violations, 40min debt)
        $root = new HtmlTreeNode('project', '<project>', 'project');
        $ns = new HtmlTreeNode('App', 'App', 'namespace');

        $classA = new HtmlTreeNode('ClassA', 'App\\ClassA', 'class');
        $classA->violations = [
            ['subject' => 'declaration:class:s@f:0', 'ruleName' => 'r1', 'violationCode' => 'r1', 'message' => 'm', 'recommendation' => null, 'severity' => 'warning', 'metricValue' => 1, 'symbolPath' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 1],
        ];
        $classA->debtMinutes = 20;

        $classB = new HtmlTreeNode('ClassB', 'App\\ClassB', 'class');
        $classB->violations = [
            ['subject' => 'declaration:class:s@f:1', 'ruleName' => 'r2', 'violationCode' => 'r2', 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 2, 'symbolPath' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 2],
            ['subject' => 'declaration:class:s@f:2', 'ruleName' => 'r3', 'violationCode' => 'r3', 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 3, 'symbolPath' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 3],
        ];
        $classB->debtMinutes = 40;

        $ns->children = [$classA, $classB];
        $root->children = [$ns];

        $total = $this->calculator->aggregateBottomUp($root);

        self::assertSame(3, $total);
        self::assertSame(3, $root->violationCountTotal);
        self::assertSame(3, $ns->violationCountTotal);
        self::assertSame(60, $ns->debtMinutes); // 20 + 40
        self::assertSame(60, $root->debtMinutes); // propagated from ns
    }
}
