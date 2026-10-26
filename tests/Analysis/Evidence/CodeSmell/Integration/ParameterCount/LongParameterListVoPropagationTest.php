<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Integration\ParameterCount;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\ConstructorOverinjectionOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\ConstructorOverinjectionRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\LongParameterListOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\LongParameterListRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\ParameterCountCollector;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\ParameterCountVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;

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
 *
 * It owns the seam only. Detection and the flag on the per-method MetricBag are
 * ParameterCountCollectorTest's, the threshold branch that reads the flag is
 * LongParameterListRuleTest's, and neither can fail for a seam that drops it.
 */
#[CoversClass(ParameterCountVisitor::class)]
#[CoversClass(ParameterCountCollector::class)]
#[CoversClass(LongParameterListRule::class)]
#[CoversClass(ConstructorOverinjectionRule::class)]
#[Group('regression')]
final class LongParameterListVoPropagationTest extends TestCase
{
    #[Test]
    public function itReportsNoFindingForVoConstructorBelowVoThresholds(): void
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

        $findings = $this->analyzeConstruct(
            $code,
            'App\Dto',
            'BigDto',
            new LongParameterListOptions(warning: 4, error: 6, voWarning: 20, voError: 30),
        );

        // 10 < vo-warning (20) — no finding at all, despite exceeding the
        // regular (non-VO) error threshold of 6.
        self::assertSame([], $findings);
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

        $findings = $this->analyzeConstruct(
            $code,
            'App\Dto',
            'BigDto',
            new LongParameterListOptions(warning: 4, error: 6, voWarning: 5, voError: 30),
        );

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('VO constructor has 10 promoted parameters', $findings[0]->message);
    }

    #[Test]
    public function itLeavesANonVoConstructorToConstructorOverinjectionWhateverTheVoThresholds(): void
    {
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

        $options = new LongParameterListOptions(warning: 4, error: 6, voWarning: 20, voError: 30);

        self::assertSame([], $this->analyzeConstruct($code, 'App\Service', 'BigService', $options));

        $owned = $this->analyzeConstruct($code, 'App\Service', 'BigService', $options, new ConstructorOverinjectionOptions(warning: 8, error: 12));
        self::assertCount(1, $owned);
        self::assertSame('code-smell.constructor-overinjection', $owned[0]->ruleName);
        self::assertSame(Severity::Warning, $owned[0]->severity);
    }

    #[Test]
    public function itLeavesAReadonlyServiceWithHiddenDependenciesToConstructorOverinjection(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

interface Sink { public function write(string $value): void; }

final readonly class Fanout
{
    public function __construct(private Sink $a, private Sink $b, private Sink $c, private Sink $d, private Sink $e, private Sink $f, private Sink $g) {}

    public function deliver(string $value): void
    {
        $this->a->write($value);
        $this->g->write($value);
    }
}
PHP;

        self::assertSame([], $this->analyzeConstruct($code, 'App\Service', 'Fanout', new LongParameterListOptions(warning: 6, error: 8)));

        $owned = $this->analyzeConstruct($code, 'App\Service', 'Fanout', new LongParameterListOptions(warning: 6, error: 8), new ConstructorOverinjectionOptions(warning: 6, error: 8));
        self::assertCount(1, $owned);
        self::assertSame('code-smell.constructor-overinjection', $owned[0]->ruleName);
    }

    /**
     * @return iterable<string, array{int, ?Severity}>
     */
    public static function provideServiceSizes(): iterable
    {
        yield 'six dependencies' => [6, null];
        yield 'seven dependencies' => [7, null];
        yield 'eight dependencies' => [8, Severity::Warning];
    }

    #[Test]
    #[DataProvider('provideServiceSizes')]
    public function itReportsAServiceConstructorOnceAndOnlyByConstructorOverinjection(int $dependencies, ?Severity $expected): void
    {
        $promoted = implode(', ', array_map(static fn(int $i): string => 'private Dependency $d' . $i, range(1, $dependencies)));
        $code = "<?php\n\nnamespace App\\Service;\n\ninterface Dependency {}\n\nfinal readonly class Handler\n{\n    public function __construct({$promoted}) {}\n\n    public function handle(): void {}\n}\n";

        $findings = $this->analyzeConstruct($code, 'App\Service', 'Handler', new LongParameterListOptions(), new ConstructorOverinjectionOptions());

        if ($expected === null) {
            self::assertSame([], $findings);

            return;
        }
        self::assertCount(1, $findings);
        self::assertSame('code-smell.constructor-overinjection', $findings[0]->ruleName);
        self::assertSame($expected, $findings[0]->severity);
    }

    /**
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Finding>
     */
    private function analyzeConstruct(
        string $code,
        string $namespace,
        string $class,
        LongParameterListOptions $options,
        ?ConstructorOverinjectionOptions $overinjection = null,
    ): array {
        $collector = new ParameterCountCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $indexAwareVisitor = $collector->getVisitor();
        self::assertInstanceOf(DeclarationIndexAwareInterface::class, $indexAwareVisitor);
        $indexAwareVisitor->useDeclarationIndex($registrar->index());
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
            $construct->startFilePos,
        );

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$symbolInfo]);
        $repository->method('getSubject')
            ->willReturn($construct->metrics);

        $context = new AnalysisContext($repository);

        $findings = (new LongParameterListRule($options))->analyze($context);
        if ($overinjection !== null) {
            $findings = [...$findings, ...(new ConstructorOverinjectionRule($overinjection))->analyze($context)];
        }

        return $findings;
    }
}
