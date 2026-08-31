<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Integration;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\DataClass\DataClassExclusionCheck;
use Qualimetrix\Analysis\Evidence\Design\DataClass\DataClassOptions;
use Qualimetrix\Analysis\Evidence\Design\DataClass\DataClassRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Evidence\Size\MethodCountCollector;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;

/**
 * Drives the rule from PHP source rather than from a hand-written metric bag.
 *
 * The inversion this test guards against survived a full unit suite precisely
 * because every existing case fed the rule synthetic WOC values: a bag can
 * assert what the rule does with a number, never what the number means.
 */
#[CoversClass(DataClassRule::class)]
#[CoversClass(DataClassExclusionCheck::class)]
#[CoversClass(MethodCountCollector::class)]
final class DataClassDetectionTest extends TestCase
{
    private const string ACCESSOR_ONLY = <<<'PHP'
        <?php
        namespace App;
        class UserProfile
        {
            private string $name;
            private string $email;
            private string $phone;

            public function getName(): string { return $this->name; }
            public function setName(string $name): void { $this->name = $name; }
            public function getEmail(): string { return $this->email; }
            public function setEmail(string $email): void { $this->email = $email; }
            public function getPhone(): string { return $this->phone; }
            public function setPhone(string $phone): void { $this->phone = $phone; }
        }
        PHP;

    private const string PUBLIC_FIELDS = <<<'PHP'
        <?php
        namespace App;
        class Row
        {
            public string $name = '';
            public int $id = 0;
            public bool $active = false;

            public function getName(): string { return $this->name; }
            public function setName(string $name): void { $this->name = $name; }
            public function getId(): int { return $this->id; }
        }
        PHP;

    private const string DELEGATING = <<<'PHP'
        <?php
        namespace App;
        class RegistrarVisitor
        {
            private array $index = [];

            public function __construct(private object $scope) {}

            public function enterNode(object $node): void { $this->scope->enter($node); }
            public function leaveNode(object $node): void { $this->scope->leave($node); }
            public function index(): array { return $this->index; }
        }
        PHP;

    private const string STRUCT = <<<'PHP'
        <?php
        namespace App;
        class Struct
        {
            public int $x = 0;
            public int $y = 0;
            public string $label = '';
        }
        PHP;

    private const string ON_THE_THRESHOLD = <<<'PHP'
        <?php
        namespace App;
        class Registry
        {
            private array $items = [];

            public function getItems(): array { return $this->items; }
            public function setItems(array $items): void { $this->items = $items; }
            public function reset(): void { $this->items = []; }
        }
        PHP;

    private const string ACCESSOR_TRAIT = <<<'PHP'
        <?php
        namespace App;
        trait AccessorTrait
        {
            private string $a = '';

            public function getA(): string { return $this->a; }
            public function setA(string $v): void { $this->a = $v; }
            public function doIt(): void {}
        }
        PHP;

    private const string INHERITED_ACCESSORS = <<<'PHP'
        <?php
        namespace App;
        class AccessorBase
        {
            private string $a = '';

            public function getA(): string { return $this->a; }
            public function setA(string $v): void { $this->a = $v; }
        }
        class Behavioural extends AccessorBase
        {
            private int $count = 0;

            public function tick(): void { ++$this->count; }
        }
        PHP;

    private const string ENCAPSULATED = <<<'PHP'
        <?php
        namespace App;
        class Sealed
        {
            private string $a = '';

            protected function shape(): void {}
            private function check(): void {}
            private function reset(): void {}
        }
        PHP;

    private const string BEHAVIOUR = <<<'PHP'
        <?php
        namespace App;
        class Collection
        {
            private array $items = [];

            public function add(string $item): void { $this->items[] = trim($item); }
            public function render(string $glue): string { return implode($glue, $this->items); }
            public function purge(): void { $this->items = []; }
        }
        PHP;

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideClassShapes(): iterable
    {
        yield 'accessors only' => [self::ACCESSOR_ONLY, 'App\UserProfile', true];
        yield 'public fields plus accessors' => [self::PUBLIC_FIELDS, 'App\Row', true];
        yield 'delegating methods' => [self::DELEGATING, 'App\RegistrarVisitor', false];
        yield 'behaviour methods' => [self::BEHAVIOUR, 'App\Collection', false];
        yield 'struct without methods' => [self::STRUCT, 'App\Struct', true];
        yield 'accessor trait' => [self::ACCESSOR_TRAIT, 'App\AccessorTrait', true];
        yield 'no public members' => [self::ENCAPSULATED, 'App\Sealed', false];
    }

    #[Test]
    #[DataProvider('provideClassShapes')]
    public function itFlagsDataAccessInterfacesAndSparesBehaviourOnes(string $code, string $class, bool $expected): void
    {
        $reported = array_map(
            static fn(Finding $finding): string => $finding->symbolPath->toString(),
            $this->analyze($code),
        );

        self::assertSame(
            $expected ? [$class] : [],
            $reported,
            $class . ' should ' . ($expected ? '' : 'not ') . 'be reported as a Data Class',
        );
    }

    #[Test]
    public function itTreatsTheThresholdItselfAsAFinding(): void
    {
        // One functional method against three public members: exactly 33%.
        // The bound is inclusive, so the canonical one-third is reported.
        $findings = $this->analyze(self::ON_THE_THRESHOLD);

        self::assertCount(1, $findings);
        self::assertSame(33, $findings[0]->metricValue);
    }

    #[Test]
    public function itKeepsTheConstructorOutOfTheRatio(): void
    {
        // UserProfile has no constructor and scores 0; adding one must not
        // move the score, or every small Data Class would sit near the bound.
        $withConstructor = str_replace(
            'public function getName()',
            "public function __construct() {}\n    public function getName()",
            self::ACCESSOR_ONLY,
        );

        $findings = $this->analyze($withConstructor);

        self::assertCount(1, $findings);
        self::assertSame(0, $findings[0]->metricValue);
    }

    #[Test]
    public function itSeesOnlyMembersDeclaredByTheClassItself(): void
    {
        // The base is the Data Class; the subclass that inherits its accessors
        // and adds behaviour is not measured through them.
        $reported = array_map(
            static fn(Finding $finding): string => $finding->symbolPath->toString(),
            $this->analyze(self::INHERITED_ACCESSORS),
        );

        self::assertSame(['App\AccessorBase'], $reported);
    }

    #[Test]
    public function itSparesAClassWhoseComplexityExceedsTheWmcGate(): void
    {
        $findings = $this->analyze(self::ACCESSOR_ONLY, wmc: 11);

        self::assertSame([], $findings);
    }

    #[Test]
    public function itReportsTheWocShareItGatedOn(): void
    {
        $findings = $this->analyze(self::ACCESSOR_ONLY);

        self::assertCount(1, $findings);
        self::assertSame(0, $findings[0]->metricValue);
        self::assertStringContainsString('only 0% of the public interface is behavior', $findings[0]->message);
    }

    /**
     * @return list<Finding>
     */
    private function analyze(string $code, int $wmc = 5): array
    {
        $collector = new MethodCountCollector();
        $collector->useDeclarationIndex(new FileDeclarationIndex());

        $ast = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        $repository = new InMemoryMetricRepository();

        foreach ($collector->getClassesWithMetrics(RelativePath::fromString('src/Subject.php')) as $class) {
            // WMC is the rule's other axis; it is aggregated from callable CCN
            // in Measurement, which this collector-only stand does not run.
            $repository->addSubject(
                $class->subject,
                $class->metrics->with(MetricName::COMPLEXITY_WMC, $wmc),
                RelativePath::fromString('src/Subject.php'),
                $class->line,
            );
        }

        return (new DataClassRule(new DataClassOptions()))->analyze(new AnalysisContext($repository));
    }
}
