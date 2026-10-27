<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Integration\ParameterCount;

use LogicException;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\ConstructorOverinjectionOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\ConstructorOverinjectionRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\LongParameterListOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\LongParameterListRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount\ParameterCountCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;

/**
 * `code-smell.constructor-overinjection` keeps its own gates; `code-smell.long-parameter-list`
 * abstains on the published injection-constructor flag. The two are one partition only while the
 * collector's condition and the gates agree, and nothing else compares them: this test runs both
 * rules, unchanged and with the real collector output, over every kind of constructor.
 */
#[CoversClass(ParameterCountCollector::class)]
#[CoversClass(LongParameterListRule::class)]
#[CoversClass(ConstructorOverinjectionRule::class)]
final class ConstructorOwnershipEquivalenceTest extends TestCase
{
    private const string FIXTURE = <<<'PHP'
<?php

namespace App;

final readonly class ValueCarrier
{
    public function __construct(public string $a, public string $b, public string $c) {}
}

final class Service
{
    public function __construct(private object $a, private object $b, private object $c) {}

    public function handle(string $a, string $b, string $c): void {}
}

final class ShoutedService
{
    public function __CONSTRUCT(private object $a, private object $b, private object $c) {}
}

final readonly class HiddenDependencies
{
    public function __construct(private object $a, private object $b, private object $c) {}
}

abstract class Base
{
    abstract public function __construct(object $a, object $b, object $c);
}

interface Contract
{
    public function __construct(object $a, object $b, object $c);
}

trait Wiring
{
    public function __construct(private object $a, private object $b, private object $c) {}
}

final class WithClosure
{
    public function __construct(private object $a)
    {
        $this->callback = static fn(object $x, object $y, object $z): bool => true;
    }
}

function __construct(object $a, object $b, object $c): void {}

function make(): object
{
    return new class (1, 2, 3) {
        public function __construct(public int $a, public int $b, public int $c) {}
    };
}

function makeService(): object
{
    return new class (1, 2, 3) {
        public function __construct(private int $a, private int $b, private int $c) {}
    };
}
PHP;

    #[Test]
    public function itGivesEveryConstructorToExactlyOneRule(): void
    {
        [$callables, $lpl, $overinjection] = $this->judge(self::FIXTURE);

        $constructors = 0;
        foreach ($callables as $key => $callable) {
            if (strtolower($callable->declarationPath->logical->member ?? '') !== '__construct') {
                continue;
            }
            ++$constructors;
            self::assertNotSame(
                isset($lpl[$key]),
                isset($overinjection[$key]),
                \sprintf('%s must be judged by exactly one of the two rules', $key),
            );
        }
        self::assertGreaterThanOrEqual(10, $constructors);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideConstructorKinds(): iterable
    {
        yield 'value-object constructor' => ['App\ValueCarrier::__construct#', false];
        yield 'service constructor' => ['App\Service::__construct#', true];
        yield 'constructor in another letter case' => ['App\ShoutedService::__CONSTRUCT#', true];
        yield 'readonly service with hidden dependencies' => ['App\HiddenDependencies::__construct#', true];
        yield 'abstract constructor' => ['App\Base::__construct#', true];
        yield 'interface constructor' => ['App\Contract::__construct#', true];
        yield 'trait constructor' => ['App\Wiring::__construct#', true];
        yield 'constructor holding a closure' => ['App\WithClosure::__construct#', true];
        yield 'anonymous class constructor' => ['App\{anonymous#0}::__construct#', true];
        yield 'global function named like a constructor' => ['App\__construct#', false];
        yield 'ordinary method' => ['App\Service::handle#', false];
        yield 'closure inside a constructor' => ['App\WithClosure::__construct::{closure#1}#', false];
    }

    #[Test]
    #[DataProvider('provideConstructorKinds')]
    public function itAssignsEachConstructorKindToItsOwner(string $keyPrefix, bool $ownedByOverinjection): void
    {
        [$callables, $lpl, $overinjection] = $this->judge(self::FIXTURE);

        $matches = array_values(array_filter(array_keys($callables), static fn(string $key): bool => str_starts_with($key, $keyPrefix)));
        self::assertCount(1, $matches, $keyPrefix);
        self::assertSame($ownedByOverinjection, isset($overinjection[$matches[0]]));
        self::assertSame(!$ownedByOverinjection, isset($lpl[$matches[0]]));
    }

    /**
     * @return array{array<string, CallableWithMetrics>, array<string, true>, array<string, true>}
     */
    private function judge(string $code): array
    {
        $collector = new ParameterCountCollector();
        $ast = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $visitor = $collector->getVisitor();
        self::assertInstanceOf(DeclarationIndexAwareInterface::class, $visitor);
        $visitor->useDeclarationIndex($registrar->index());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $file = RelativePath::fromString('src/example.php');
        $callables = [];
        $infos = [];
        $bags = [];
        foreach ($collector->getCallablesWithMetrics($file) as $index => $callable) {
            $logical = $callable->declarationPath->logical;
            $key = $logical->namespace . '\\' . ($logical->type !== null ? $logical->type . '::' : '') . $logical->member . '#' . $index;
            $callables[$key] = $callable;
            $subject = MetricSubject::declaration($callable->declarationPath);
            $infos[$key] = new SymbolInfo($subject, $file, $callable->startFilePos);
            $bags[$subject->toCanonical()] = $callable->metrics;
        }

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn(array_values($infos));
        $repository->method('getSubject')->willReturnCallback(static fn(MetricSubject $subject) => $bags[$subject->toCanonical()]);
        $context = new AnalysisContext($repository);

        $lplFindings = (new LongParameterListRule(new LongParameterListOptions(warning: 1, error: 99, voWarning: 1, voError: 99)))->analyze($context);
        $overinjectionFindings = (new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(warning: 1, error: 99)))->analyze($context);

        $keyOf = static function (Finding $finding) use ($infos): string {
            foreach ($infos as $key => $info) {
                if ($info->subject?->toCanonical() === $finding->subject->toCanonical()) {
                    return $key;
                }
            }

            throw new LogicException('Finding without a fixture callable');
        };

        return [
            $callables,
            array_fill_keys(array_map($keyOf, $lplFindings), true),
            array_fill_keys(array_map($keyOf, $overinjectionFindings), true),
        ];
    }
}
