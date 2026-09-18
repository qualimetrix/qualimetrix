<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\RepeatedExpression\IdenticalSubExpressionCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use SplFileInfo;

#[CoversClass(IdenticalSubExpressionCollector::class)]
final class IdenticalSubExpressionCollectorTest extends TestCase
{
    private IdenticalSubExpressionCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new IdenticalSubExpressionCollector();
    }

    #[Test]
    public function itReturnsCollectorName(): void
    {
        self::assertSame('identical-subexpression', $this->collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetricKeys(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('identicalSubExpression.identical_operands', $provides);
        self::assertContains('identicalSubExpression.duplicate_condition', $provides);
        self::assertContains('identicalSubExpression.identical_ternary', $provides);
        self::assertContains('identicalSubExpression.duplicate_match_arm', $provides);
        self::assertContains('identicalSubExpression.duplicate_switch_case', $provides);
    }

    #[Test]
    public function itIntegratesWithCollectorCorrectly(): void
    {
        $code = <<<'PHP'
<?php
$a = $x === $x;
if ($y > 0) {} elseif ($y > 0) {}
PHP;

        $bag = $this->collect($code);

        self::assertSame(1, $bag->entryCount('identicalSubExpression.identical_operands'));
        self::assertSame(2, $bag->entries('identicalSubExpression.identical_operands')[0]['line']);
        self::assertSame(1, $bag->entryCount('identicalSubExpression.duplicate_condition'));
        self::assertSame(3, $bag->entries('identicalSubExpression.duplicate_condition')[0]['line']);
        self::assertSame(0, $bag->entryCount('identicalSubExpression.identical_ternary'));
        self::assertSame(0, $bag->entryCount('identicalSubExpression.duplicate_match_arm'));
    }

    #[Test]
    public function itProducesNoCollectorFindingsForCleanCode(): void
    {
        $bag = $this->collect('<?php $a = $x + $y;');

        foreach (IdenticalSubExpressionCollector::FINDING_TYPES as $type) {
            self::assertSame(0, $bag->entryCount("identicalSubExpression.{$type}"));
        }
    }

    #[Test]
    public function itDeliberatelyDoesNotProvideCallableMetrics(): void
    {
        self::assertNotContains(\Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface::class, class_implements($this->collector));
    }

    private function collect(string $code): MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $indexAwareVisitor = $this->collector->getVisitor();
        self::assertInstanceOf(DeclarationIndexAwareInterface::class, $indexAwareVisitor);
        $indexAwareVisitor->useDeclarationIndex($registrar->index());
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}
