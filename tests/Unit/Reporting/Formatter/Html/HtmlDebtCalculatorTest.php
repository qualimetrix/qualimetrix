<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Formatter\Html\HtmlDebtCalculator;
use Qualimetrix\Reporting\Formatter\Html\HtmlTreeNode;

#[CoversClass(HtmlDebtCalculator::class)]
final class HtmlDebtCalculatorTest extends TestCase
{
    private HtmlDebtCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new HtmlDebtCalculator(
            new DebtCalculator(new RemediationTimeRegistry()),
        );
    }

    public function testComputeDebtNoViolations(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $this->calculator->computeDebt([], ['App\\Service' => $node]);

        self::assertSame(0, $node->debtMinutes);
    }

    public function testComputeDebtWithViolations(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $violation = new Violation(
            location: new Location('src/Service.php', 10),
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

    public function testComputeDebtSkipsUnknownNodePaths(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $violation = new Violation(
            location: new Location('src/Other.php', 10),
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

    public function testAggregateBottomUpWithNoChildren(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');
        $node->violations = [
            ['ruleName' => 'r1', 'violationCode' => 'r1', 'message' => 'm', 'recommendation' => null, 'severity' => 'warning', 'metricValue' => 1, 'symbolPath' => 's', 'file' => 'f', 'line' => 1],
            ['ruleName' => 'r2', 'violationCode' => 'r2', 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 2, 'symbolPath' => 's', 'file' => 'f', 'line' => 2],
        ];
        $node->debtMinutes = 60;

        $total = $this->calculator->aggregateBottomUp($node);

        self::assertSame(2, $total);
        self::assertSame(2, $node->violationCountTotal);
        self::assertSame(60, $node->debtMinutes); // No children, debt unchanged
    }

    public function testAggregateBottomUpSumsChildViolationsAndDebt(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');

        $childA = new HtmlTreeNode('A', 'App\\A', 'class');
        $childA->violations = [
            ['ruleName' => 'r1', 'violationCode' => 'r1', 'message' => 'm', 'recommendation' => null, 'severity' => 'warning', 'metricValue' => 1, 'symbolPath' => 's', 'file' => 'f', 'line' => 1],
        ];
        $childA->debtMinutes = 30;

        $childB = new HtmlTreeNode('B', 'App\\B', 'class');
        $childB->violations = [
            ['ruleName' => 'r2', 'violationCode' => 'r2', 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 2, 'symbolPath' => 's', 'file' => 'f', 'line' => 2],
            ['ruleName' => 'r3', 'violationCode' => 'r3', 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 3, 'symbolPath' => 's', 'file' => 'f', 'line' => 3],
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

    public function testAggregateBottomUpDeepHierarchy(): void
    {
        // Root -> NS -> ClassA (1 violation, 20min debt)
        //                ClassB (2 violations, 40min debt)
        $root = new HtmlTreeNode('project', '<project>', 'project');
        $ns = new HtmlTreeNode('App', 'App', 'namespace');

        $classA = new HtmlTreeNode('ClassA', 'App\\ClassA', 'class');
        $classA->violations = [
            ['ruleName' => 'r1', 'violationCode' => 'r1', 'message' => 'm', 'recommendation' => null, 'severity' => 'warning', 'metricValue' => 1, 'symbolPath' => 's', 'file' => 'f', 'line' => 1],
        ];
        $classA->debtMinutes = 20;

        $classB = new HtmlTreeNode('ClassB', 'App\\ClassB', 'class');
        $classB->violations = [
            ['ruleName' => 'r2', 'violationCode' => 'r2', 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 2, 'symbolPath' => 's', 'file' => 'f', 'line' => 2],
            ['ruleName' => 'r3', 'violationCode' => 'r3', 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 3, 'symbolPath' => 's', 'file' => 'f', 'line' => 3],
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
