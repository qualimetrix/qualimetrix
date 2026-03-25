<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Design;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\Design\TypeCoverageCollector;
use Qualimetrix\Metrics\Design\TypeCoverageVisitor;
use SplFileInfo;

/**
 * Documents that TypeCoverage uses percentages (0-100), not ratios (0-1).
 *
 * src/Metrics/README.md:47 states TypeCoverage metrics are "ratios (0-1)",
 * but the actual implementation calculates percentages (0-100).
 * This test documents the current (correct) behavior.
 */
#[CoversClass(TypeCoverageCollector::class)]
#[CoversClass(TypeCoverageVisitor::class)]
#[Group('regression')]
final class TypeCoverageScaleTest extends TestCase
{
    private TypeCoverageCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new TypeCoverageCollector();
    }

    #[Test]
    public function fullyTypedClassProducesPercentage100NotRatio1(): void
    {
        // Documents that TypeCoverage uses percentages (0-100), not ratios (0-1).
        // If the metric were a ratio, we would expect 1.0 for full coverage.
        $code = <<<'PHP'
<?php

namespace App\Service;

class FullyTypedService
{
    private string $name;
    private int $age;

    public function getName(): string
    {
        return $this->name;
    }

    public function setAge(int $age): void
    {
        $this->age = $age;
    }

    public function process(string $input, int $count): bool
    {
        return true;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // All parameters are typed: 3/3
        self::assertSame(
            100.0,
            $metrics->get('typeCoverage.param:App\Service\FullyTypedService'),
            'TypeCoverage.param uses percentages (100.0 for full coverage), not ratios (1.0)',
        );

        // All methods have return types: 3/3
        self::assertSame(
            100.0,
            $metrics->get('typeCoverage.return:App\Service\FullyTypedService'),
            'TypeCoverage.return uses percentages (100.0 for full coverage), not ratios (1.0)',
        );

        // All properties are typed: 2/2
        self::assertSame(
            100.0,
            $metrics->get('typeCoverage.property:App\Service\FullyTypedService'),
            'TypeCoverage.property uses percentages (100.0 for full coverage), not ratios (1.0)',
        );
    }

    #[Test]
    public function halfTypedClassProducesPercentage50NotRatio05(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class HalfTypedService
{
    private string $typed;
    public $untyped;

    public function typedMethod(int $a): void
    {
    }

    public function untypedMethod($b)
    {
        return $b;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 1 typed param out of 2 = 50%, not 0.5
        self::assertSame(
            50.0,
            $metrics->get('typeCoverage.param:App\HalfTypedService'),
            'Half-typed params should be 50.0 (percentage), not 0.5 (ratio)',
        );

        // 1 typed return out of 2 = 50%, not 0.5
        self::assertSame(
            50.0,
            $metrics->get('typeCoverage.return:App\HalfTypedService'),
            'Half-typed returns should be 50.0 (percentage), not 0.5 (ratio)',
        );

        // 1 typed property out of 2 = 50%, not 0.5
        self::assertSame(
            50.0,
            $metrics->get('typeCoverage.property:App\HalfTypedService'),
            'Half-typed properties should be 50.0 (percentage), not 0.5 (ratio)',
        );
    }

    #[Test]
    public function unTypedClassProducesPercentage0NotRatio0(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class UntypedService
{
    public $data;

    public function process($input)
    {
        return $input;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 0 typed out of 1 = 0.0 (same for both ratio and percentage,
        // but included for completeness)
        self::assertSame(0.0, $metrics->get('typeCoverage.param:App\UntypedService'));
        self::assertSame(0.0, $metrics->get('typeCoverage.return:App\UntypedService'));
        self::assertSame(0.0, $metrics->get('typeCoverage.property:App\UntypedService'));
    }

    #[Test]
    public function partialCoverageProducesCorrectPercentage(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class PartialService
{
    public function method(int $a, $b, string $c): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 2 typed out of 3 params = 66.67%, not 0.6667
        self::assertSame(
            66.67,
            $metrics->get('typeCoverage.param:App\PartialService'),
            'Partial coverage should be 66.67 (percentage), not 0.6667 (ratio)',
        );

        // Verify it's clearly in the 0-100 range, not 0-1
        $paramCoverage = $metrics->get('typeCoverage.param:App\PartialService');
        self::assertGreaterThan(
            1.0,
            $paramCoverage,
            'TypeCoverage values should be > 1.0 for partial coverage, '
            . 'confirming percentage scale (0-100) not ratio scale (0-1)',
        );
    }

    private function collectMetrics(string $code): \Qualimetrix\Core\Metric\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}
