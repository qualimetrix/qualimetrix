<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Rules;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Metrics\CodeSmell\ParameterCountCollector;
use Qualimetrix\Metrics\CodeSmell\ParameterCountVisitor;
use Qualimetrix\Rules\CodeSmell\LongParameterListOptions;
use Qualimetrix\Rules\CodeSmell\LongParameterListRule;

/**
 * Regression test: FileProcessor builds per-symbol metrics exclusively from
 * ParameterCountCollector::getCallablesWithMetrics() (via CallableWithMetrics), not
 * from the file-level MetricBag produced by collect(). Before the fix,
 * getCallablesWithMetrics() never included the isVoConstructor flag, so
 * LongParameterListRule always evaluated VO constructors against the regular
 * (non-VO) thresholds — the `--long-parameter-list-vo-warning` /
 * `--long-parameter-list-vo-error` options were silently ignored end-to-end.
 *
 * This test wires the real ParameterCountCollector output straight into the
 * real LongParameterListRule, mirroring exactly the data FileProcessor would
 * produce, without touching FileProcessor itself.
 */
#[CoversClass(ParameterCountVisitor::class)]
#[CoversClass(ParameterCountCollector::class)]
#[CoversClass(LongParameterListRule::class)]
#[Group('regression')]
final class LongParameterListVoPropagationTest extends TestCase
{
    #[Test]
    public function itReportsNoViolationForVoConstructorBelowVoThresholds(): void
    {
        // final readonly class, 10 promoted parameters, empty constructor body.
        $code = <<<'PHP'
<?php

namespace App\Dto;

final readonly class BigDto
{
    public function __construct(
        public string $a,
        public string $b,
        public string $c,
        public string $d,
        public string $e,
        public string $f,
        public string $g,
        public string $h,
        public string $i,
        public string $j,
    ) {}
}
PHP;

        $violations = $this->analyzeConstruct(
            $code,
            'App\Dto',
            'BigDto',
            new LongParameterListOptions(warning: 4, error: 6, voWarning: 20, voError: 30),
        );

        // 10 < vo-warning (20) — no violation at all, despite exceeding the
        // regular (non-VO) error threshold of 6.
        self::assertSame([], $violations);
    }

    #[Test]
    public function itReportsVoWarningWhenVoThresholdIsExceeded(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

final readonly class BigDto
{
    public function __construct(
        public string $a,
        public string $b,
        public string $c,
        public string $d,
        public string $e,
        public string $f,
        public string $g,
        public string $h,
        public string $i,
        public string $j,
    ) {}
}
PHP;

        $violations = $this->analyzeConstruct(
            $code,
            'App\Dto',
            'BigDto',
            new LongParameterListOptions(warning: 4, error: 6, voWarning: 5, voError: 30),
        );

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('VO constructor has 10 promoted parameters', $violations[0]->message);
    }

    #[Test]
    public function itUsesRegularThresholdsForNonVoConstructorEvenWithRelaxedVoThresholds(): void
    {
        // Non-readonly class with 10 non-promoted parameters — must never take
        // the VO branch, regardless of how relaxed the VO thresholds are.
        $code = <<<'PHP'
<?php

namespace App\Service;

class BigService
{
    public function __construct(
        string $a,
        string $b,
        string $c,
        string $d,
        string $e,
        string $f,
        string $g,
        string $h,
        string $i,
        string $j,
    ) {}
}
PHP;

        $violations = $this->analyzeConstruct(
            $code,
            'App\Service',
            'BigService',
            new LongParameterListOptions(warning: 4, error: 6, voWarning: 20, voError: 30),
        );

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('Method has 10 parameters, exceeds threshold of 6. Consider introducing a parameter object', $violations[0]->message);
    }

    /**
     * @return list<\Qualimetrix\Core\Violation\Violation>
     */
    private function analyzeConstruct(
        string $code,
        string $namespace,
        string $class,
        LongParameterListOptions $options,
    ): array {
        $collector = new ParameterCountCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        $methodsWithMetrics = $collector->getCallablesWithMetrics(RelativePath::fromString('src/example.php'));

        $construct = null;
        foreach ($methodsWithMetrics as $methodWithMetrics) {
            $logical = $methodWithMetrics->declarationPath->logical;
            if ($logical->type === $class && $logical->member === '__construct') {
                $construct = $methodWithMetrics;

                break;
            }
        }

        self::assertInstanceOf(CallableWithMetrics::class, $construct, \sprintf('No __construct found for %s\\%s', $namespace, $class));

        $symbolInfo = new SymbolInfo(
            MetricSubject::declaration($construct->declarationPath),
            RelativePath::fromString('src/example.php'),
            $construct->declarationPath->startFilePos,
        );

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$symbolInfo]);
        $repository->method('getSubject')
            ->willReturn($construct->metrics);

        $context = new AnalysisContext($repository);

        return (new LongParameterListRule($options))->analyze($context);
    }
}
