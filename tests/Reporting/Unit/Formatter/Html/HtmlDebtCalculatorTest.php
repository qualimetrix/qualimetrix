<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
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
    public function itComputesZeroDebtWithNoFindings(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $this->calculator->calculate($node, [], ['App\\Service' => $node]);

        self::assertSame(0, $node->debtMinutes);
    }

    #[Test]
    public function itComputesDebtWithFindings(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'Service'), RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'Too complex',
            severity: Severity::Warning,
            metricValue: 10,
        );

        $this->calculator->calculate(
            $node,
            ['App\\Service' => [$finding]],
            ['App\\Service' => $node],
        );

        // complexity.ccn = 30 minutes per RemediationTimeRegistry
        self::assertSame(30, $node->debtMinutes);
    }

    #[Test]
    public function itSkipsUnknownNodePathsWhenComputingDebt(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Other.php'), 10),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'Other'), RelativePath::fromString('src/Other.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App', 'Other'),
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $this->calculator->calculate(
            $node,
            ['App\\Other' => [$finding]],
            ['App\\Service' => $node],
        );

        self::assertSame(0, $node->debtMinutes);
    }

    #[Test]
    public function itAggregatesBottomUpWithNoChildren(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');
        $node->findings = [
            ['subject' => 'declaration:class:s@f:0', 'rule' => 'r1', 'code' => 'r1', 'channel' => 'r1', 'edge' => null, 'namespace' => '', 'namespaces' => [''], 'threshold' => null, 'techDebtMinutes' => 0, 'message' => 'm', 'recommendation' => null, 'severity' => 'warning', 'metricValue' => 1, 'symbol' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 1, 'acceptedLevel' => null, 'baselineVerdict' => null, 'baselineReason' => null],
            ['subject' => 'declaration:class:s@f:1', 'rule' => 'r2', 'code' => 'r2', 'channel' => 'r2', 'edge' => null, 'namespace' => '', 'namespaces' => [''], 'threshold' => null, 'techDebtMinutes' => 0, 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 2, 'symbol' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 2, 'acceptedLevel' => null, 'baselineVerdict' => null, 'baselineReason' => null],
        ];
        $node->debtMinutes = 60;

        $total = $this->calculator->calculate($node, [], []);

        self::assertSame(2, $total);
        self::assertSame(2, $node->violationCountTotal);
        self::assertSame(60, $node->debtMinutes); // No children, debt unchanged
    }

    #[Test]
    public function itSumsChildFindingsAndDebtWhenAggregatingBottomUp(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');

        $childA = new HtmlTreeNode('A', 'App\\A', 'class');
        $childA->findings = [
            ['subject' => 'declaration:class:s@f:0', 'rule' => 'r1', 'code' => 'r1', 'channel' => 'r1', 'edge' => null, 'namespace' => '', 'namespaces' => [''], 'threshold' => null, 'techDebtMinutes' => 0, 'message' => 'm', 'recommendation' => null, 'severity' => 'warning', 'metricValue' => 1, 'symbol' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 1, 'acceptedLevel' => null, 'baselineVerdict' => null, 'baselineReason' => null],
        ];
        $childA->debtMinutes = 30;

        $childB = new HtmlTreeNode('B', 'App\\B', 'class');
        $childB->findings = [
            ['subject' => 'declaration:class:s@f:1', 'rule' => 'r2', 'code' => 'r2', 'channel' => 'r2', 'edge' => null, 'namespace' => '', 'namespaces' => [''], 'threshold' => null, 'techDebtMinutes' => 0, 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 2, 'symbol' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 2, 'acceptedLevel' => null, 'baselineVerdict' => null, 'baselineReason' => null],
            ['subject' => 'declaration:class:s@f:2', 'rule' => 'r3', 'code' => 'r3', 'channel' => 'r3', 'edge' => null, 'namespace' => '', 'namespaces' => [''], 'threshold' => null, 'techDebtMinutes' => 0, 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 3, 'symbol' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 3, 'acceptedLevel' => null, 'baselineVerdict' => null, 'baselineReason' => null],
        ];
        $childB->debtMinutes = 45;

        $root->children = [$childA, $childB];

        $total = $this->calculator->calculate($root, [], []);

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
        // Root -> NS -> ClassA (1 finding, 20min debt)
        //                ClassB (2 findings, 40min debt)
        $root = new HtmlTreeNode('project', '<project>', 'project');
        $ns = new HtmlTreeNode('App', 'App', 'namespace');

        $classA = new HtmlTreeNode('ClassA', 'App\\ClassA', 'class');
        $classA->findings = [
            ['subject' => 'declaration:class:s@f:0', 'rule' => 'r1', 'code' => 'r1', 'channel' => 'r1', 'edge' => null, 'namespace' => '', 'namespaces' => [''], 'threshold' => null, 'techDebtMinutes' => 0, 'message' => 'm', 'recommendation' => null, 'severity' => 'warning', 'metricValue' => 1, 'symbol' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 1, 'acceptedLevel' => null, 'baselineVerdict' => null, 'baselineReason' => null],
        ];
        $classA->debtMinutes = 20;

        $classB = new HtmlTreeNode('ClassB', 'App\\ClassB', 'class');
        $classB->findings = [
            ['subject' => 'declaration:class:s@f:1', 'rule' => 'r2', 'code' => 'r2', 'channel' => 'r2', 'edge' => null, 'namespace' => '', 'namespaces' => [''], 'threshold' => null, 'techDebtMinutes' => 0, 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 2, 'symbol' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 2, 'acceptedLevel' => null, 'baselineVerdict' => null, 'baselineReason' => null],
            ['subject' => 'declaration:class:s@f:2', 'rule' => 'r3', 'code' => 'r3', 'channel' => 'r3', 'edge' => null, 'namespace' => '', 'namespaces' => [''], 'threshold' => null, 'techDebtMinutes' => 0, 'message' => 'm', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 3, 'symbol' => 's', 'occurrence' => null, 'file' => 'f', 'line' => 3, 'acceptedLevel' => null, 'baselineVerdict' => null, 'baselineReason' => null],
        ];
        $classB->debtMinutes = 40;

        $ns->children = [$classA, $classB];
        $root->children = [$ns];

        $total = $this->calculator->calculate($root, [], []);

        self::assertSame(3, $total);
        self::assertSame(3, $root->violationCountTotal);
        self::assertSame(3, $ns->violationCountTotal);
        self::assertSame(60, $ns->debtMinutes); // 20 + 40
        self::assertSame(60, $root->debtMinutes); // propagated from ns
    }
}
