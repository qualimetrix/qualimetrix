<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RuleDeclaration;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\ConfigurationValidatorRegistry;
use ReflectionClass;

/**
 * Every registered rule and configuration validator is stateless in itself:
 * no property an instance can reassign, no static property at all, no
 * readonly property written outside the constructor and no `static` variable
 * in a function body.
 *
 * {@see RuleInterface::analyze()} states why: one instance is shared by the
 * whole process and asked 1 + N times per run — once for the run, once per
 * authored threshold-override group inside the directive audit — and the
 * only thing that would otherwise notice a remembered value is that audit's
 * control pass, which invalidates every verdict instead of naming the class.
 *
 * A readonly property may be initialised once in any method, so "every
 * property readonly" alone admits a lazy cache: `$this->memo ??= …`. The
 * source is therefore read as well, and a write to `$this->…` — an
 * assignment, a compound or reference assignment, an increment, an `unset`,
 * or a write through an offset such as `$this->memo[] = …` — is accepted only
 * inside `__construct`.
 *
 * What this cannot see, by construction: a write through a method of an
 * object a property holds (`$this->memo->append(…)`), a write through an
 * injected collaborator, a readonly property initialised by a helper the
 * constructor calls (refused here although PHP allows it), and code reached
 * only by a dynamic call. The contract docblock says which collaborator
 * writes are allowed.
 */
final class RuleInstanceStatelessnessTest extends TestCase
{
    /** {@see RegisteredRules::COUNT} rule classes plus the two configuration validators. */
    private const int POPULATION = RegisteredRules::COUNT + 2;

    /** @var list<string> */
    private array $plantedFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->plantedFiles as $file) {
            @unlink($file);
        }
    }

    #[Test]
    public function itFindsNoStateAnInstanceCouldCarryBetweenCalls(): void
    {
        $population = [...RegisteredRules::classes(), ...self::validatorClasses()];
        $violations = [];

        foreach ($population as $class) {
            $violations = [...$violations, ...self::stateOf($class)];
        }

        self::assertCount(self::POPULATION, array_unique($population), 'the swept population moved; re-derive it before trusting an empty answer');
        self::assertSame([], $violations, "A rule or validator carries state between calls:\n" . implode("\n", $violations));
    }

    /**
     * The detector held against a planted stateful class, so that an empty
     * answer above cannot mean it stopped recognising anything.
     */
    #[Test]
    public function itRecognisesAPropertyAnInstanceCanReassignAndAStaticOne(): void
    {
        $planted = new class {
            public static int $shared = 0;

            private int $calls = 0;

            public function __construct(private readonly int $limit = 1) {}

            public function tick(): int
            {
                return ++$this->calls + $this->limit + self::$shared;
            }
        };

        $violations = self::stateOf($planted::class);

        self::assertCount(2, $violations);
        self::assertStringContainsString('$calls', $violations[0] . $violations[1]);
        self::assertStringContainsString('static $shared', $violations[0] . $violations[1]);
    }

    /**
     * Each shape that satisfies "every property readonly, none static" and
     * still carries a value from one call to the next. The classes live in
     * files written here and not in this one: static analysis of the test
     * tree would refuse them, which is the point of them.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideStateBehindAReadonlyDeclaration(): iterable
    {
        yield 'a lazy readonly cache' => [
            <<<'PHP'
                private readonly array $memo;

                public function analyze(): array
                {
                    $this->memo ??= [1];

                    return $this->memo;
                }
                PHP,
            '$memo',
        ];

        yield 'a readonly initialised on first use' => [
            <<<'PHP'
                private readonly array $seen;

                public function analyze(): array
                {
                    if (!isset($this->seen)) {
                        $this->seen = [1];
                    }

                    return $this->seen;
                }
                PHP,
            '$seen',
        ];

        yield 'an object written through its offset' => [
            <<<'PHP'
                private readonly \ArrayObject $memo;

                public function __construct()
                {
                    $this->memo = new \ArrayObject();
                }

                public function analyze(): int
                {
                    $this->memo[] = 1;

                    return \count($this->memo);
                }
                PHP,
            '$memo',
        ];

        yield 'a static variable in a closure' => [
            <<<'PHP'
                private readonly \Closure $counter;

                public function __construct()
                {
                    $this->counter = static function (): int {
                        static $calls = 0;

                        return ++$calls;
                    };
                }

                public function analyze(): int
                {
                    return ($this->counter)();
                }
                PHP,
            'static $calls',
        ];
    }

    #[Test]
    #[DataProvider('provideStateBehindAReadonlyDeclaration')]
    public function itRecognisesStateBehindAReadonlyDeclaration(string $body, string $named): void
    {
        $violations = self::stateOf($this->plant($body));

        self::assertCount(1, $violations, implode("\n", $violations));
        self::assertStringContainsString($named, $violations[0]);
    }

    /**
     * The legitimate neighbour: readonly state set in the constructor,
     * promoted or assigned, and only read afterwards.
     */
    #[Test]
    public function itAcceptsReadonlyStateSetOnlyInTheConstructor(): void
    {
        $class = $this->plant(<<<'PHP'
            private readonly array $table;

            public function __construct(private readonly int $limit = 1)
            {
                $this->table = [$limit];
            }

            public function analyze(): array
            {
                $local = $this->table;
                $local[] = $this->limit;

                return $local;
            }
            PHP);

        self::assertSame([], self::stateOf($class));
    }

    /**
     * Every property the class and its ancestors and traits declare, each
     * named once by the class that declares it, then every write the source
     * makes outside the constructor to a property the declaration pass did
     * not already name.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function stateOf(string $class): array
    {
        $violations = [];
        $named = [];
        $reflection = new ReflectionClass($class);

        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $current->getName()) {
                    continue;
                }

                if ($property->isStatic()) {
                    $violations[] = \sprintf('%s: static $%s', $current->getName(), $property->getName());
                    $named[$property->getName()] = true;

                    continue;
                }

                if (!$property->isReadOnly()) {
                    $violations[] = \sprintf('%s: $%s is not readonly', $current->getName(), $property->getName());
                    $named[$property->getName()] = true;
                }
            }
        }

        foreach (self::declaringSources($reflection) as $source) {
            $violations = [...$violations, ...self::writesOutsideTheConstructor($source, $named)];
        }

        return $violations;
    }

    /**
     * The class, its ancestors and every trait any of them uses, each once.
     *
     * @param ReflectionClass<object> $reflection
     *
     * @return list<ReflectionClass<object>>
     */
    private static function declaringSources(ReflectionClass $reflection): array
    {
        $sources = [];
        $pending = [];

        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            $pending[] = $current;
        }

        while ($pending !== []) {
            $current = array_shift($pending);
            if (isset($sources[$current->getName()])) {
                continue;
            }

            $sources[$current->getName()] = $current;
            foreach ($current->getTraits() as $trait) {
                $pending[] = $trait;
            }
        }

        return array_values($sources);
    }

    /**
     * @param ReflectionClass<object> $source
     * @param array<string, true> $named properties the declaration pass already reported
     *
     * @return list<string>
     */
    private static function writesOutsideTheConstructor(ReflectionClass $source, array $named): array
    {
        $file = $source->getFileName();
        if ($file === false) {
            return [];
        }

        $declaration = self::declarationNode($file, $source->isAnonymous() ? null : $source->getShortName(), $source->getEndLine());
        self::assertNotNull($declaration, \sprintf('the source of %s was not found where reflection places it', $source->getName()));

        $violations = [];

        foreach (self::bodyOutsideTheConstructor($declaration) as [$where, $node]) {
            if ($node instanceof Stmt\Static_) {
                foreach ($node->vars as $variable) {
                    $violations[] = \sprintf('%s: static $%s in %s', $source->getName(), self::variableName($variable->var), $where);
                }

                continue;
            }

            foreach (self::writtenTargets($node) as $target) {
                $property = self::thisProperty($target);
                if ($property === null || isset($named[$property])) {
                    continue;
                }

                $violations[] = \sprintf('%s: readonly $%s written in %s', $source->getName(), $property, $where);
            }
        }

        return $violations;
    }

    /**
     * Located by name and last line: the first line reflection reports is the
     * keyword's, while the parser's includes any attribute above it.
     */
    private static function declarationNode(string $file, ?string $shortName, int|false $endLine): ?Stmt\ClassLike
    {
        static $parsed = [];

        if (!isset($parsed[$file])) {
            $code = file_get_contents($file);
            self::assertIsString($code);
            $parsed[$file] = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        }

        $statements = $parsed[$file];

        $found = (new NodeFinder())->findFirst(
            $statements,
            static fn(Node $node): bool => $node instanceof Stmt\ClassLike
                && $node->getEndLine() === $endLine
                && $node->name?->toString() === $shortName,
        );

        return $found instanceof Stmt\ClassLike ? $found : null;
    }

    /**
     * Every node of the declaration's own body, paired with the member it
     * sits in, except the constructor's and those of a class declared inside
     * it — that class's `$this` is another object.
     *
     * @return list<array{string, Node}>
     */
    private static function bodyOutsideTheConstructor(Stmt\ClassLike $declaration): array
    {
        $nodes = [];

        foreach ($declaration->stmts as $member) {
            if ($member instanceof Stmt\ClassMethod && $member->name->toLowerString() === '__construct') {
                foreach (self::descendants($member) as $node) {
                    if ($node instanceof Stmt\Static_) {
                        $nodes[] = ['__construct', $node];
                    }
                }

                continue;
            }

            $where = $member instanceof Stmt\ClassMethod ? $member->name->toString() . '()' : 'a member initialiser';
            foreach (self::descendants($member) as $node) {
                $nodes[] = [$where, $node];
            }
        }

        return $nodes;
    }

    /**
     * The member and everything inside it, except the body of a class
     * declared there.
     *
     * @return list<Node>
     */
    private static function descendants(Node $member): array
    {
        $collector = new class extends NodeVisitorAbstract {
            /** @var list<Node> */
            public array $found = [];

            public function enterNode(Node $node): ?int
            {
                $this->found[] = $node;
                $nested = $node instanceof Stmt\ClassLike || $node instanceof Expr\New_ && $node->class instanceof Stmt\Class_;

                return $nested ? NodeVisitor::DONT_TRAVERSE_CHILDREN : null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse([$member]);

        return $collector->found;
    }

    /** @return list<Expr> */
    private static function writtenTargets(Node $node): array
    {
        $targets = match (true) {
            $node instanceof Expr\Assign, $node instanceof Expr\AssignOp, $node instanceof Expr\AssignRef => [$node->var],
            $node instanceof Expr\PreInc, $node instanceof Expr\PostInc, $node instanceof Expr\PreDec, $node instanceof Expr\PostDec => [$node->var],
            $node instanceof Stmt\Unset_ => $node->vars,
            default => [],
        };

        return array_merge([], ...array_map(self::destructured(...), $targets));
    }

    /**
     * A destructuring target `[$this->a, $b] = …` is several targets.
     *
     * @return list<Expr>
     */
    private static function destructured(Expr $target): array
    {
        if (!$target instanceof Expr\List_ && !$target instanceof Expr\Array_) {
            return [$target];
        }

        $flat = [];
        foreach ($target->items as $item) {
            if ($item !== null) {
                $flat = [...$flat, ...self::destructured($item->value)];
            }
        }

        return $flat;
    }

    /** The property of `$this` a write lands on, through any offsets; null for anything else. */
    private static function thisProperty(Expr $target): ?string
    {
        while ($target instanceof Expr\ArrayDimFetch) {
            $target = $target->var;
        }

        if (!$target instanceof Expr\PropertyFetch && !$target instanceof Expr\NullsafePropertyFetch) {
            return null;
        }

        if (!$target->var instanceof Expr\Variable || $target->var->name !== 'this') {
            return null;
        }

        return $target->name instanceof Node\Identifier ? $target->name->toString() : '{dynamic}';
    }

    private static function variableName(Expr\Variable $variable): string
    {
        return \is_string($variable->name) ? $variable->name : '{dynamic}';
    }

    /**
     * Writes a class with the given body to a file of its own, loads it, and
     * returns its name.
     *
     * @return class-string
     */
    private function plant(string $body): string
    {
        $name = 'PlantedStatelessnessProbe' . bin2hex(random_bytes(6));
        $file = sys_get_temp_dir() . '/' . $name . '.php';
        file_put_contents($file, "<?php\n\nfinal class {$name}\n{\n{$body}\n}\n");
        $this->plantedFiles[] = $file;

        require $file;

        /** @var class-string $name */
        return $name;
    }

    /** @return list<class-string<ConfigurationValidatorInterface>> */
    private static function validatorClasses(): array
    {
        $registry = (new ContainerFactory())->create()->get(ConfigurationValidatorRegistry::class);
        \assert($registry instanceof ConfigurationValidatorRegistry);

        return $registry->getClasses();
    }
}
