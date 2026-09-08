<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\RuleConfiguration\Unit;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every class that implements the option-key contract answers it itself, in the
 * spelling the refusal prints.
 *
 * The refusal walk in
 * {@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory}
 * asks exactly one question per depth — "what does the class at this depth
 * declare?" — so a class that answers it wrongly turns a working key into exit
 * 3 in silence, with no other check in the tree between the declaration and the
 * user. This file is that check, and it is deliberately *not* the guard of
 * П4.1: that one asks whether the declaration covers what `fromArray()` reads,
 * against an AST oracle. This one asks whether the population answering the
 * contract is the population that must, and whether each answer is well formed.
 *
 * **The population is read out of the tree twice, not written down here.** A
 * count in a test is a claim that stops being true the day a rule is added, and
 * stops saying so at the same moment. The first witness is an AST sweep of
 * `src/` and `tests/` — the only one that can see an anonymous test fixture,
 * which no reflection over class names ever will. The second is the container's
 * own rule registry, which knows nothing about files. Each catches what the
 * other cannot: a production class dropped from the registry, and a class that
 * exists only at runtime.
 */
#[CoversClass(RuleOptionKeySet::class)]
final class RuleOptionKeyDeclarationCoverageTest extends TestCase
{
    /**
     * A file that names the contract but cannot be parsed would leave a hole
     * in the sweep this size, and the sweep would still report success.
     */
    #[Test]
    public function itSweepsEveryFileThatCouldHoldAnImplementation(): void
    {
        $unparseable = self::unparseableFilesNamingTheContract();

        self::assertSame(
            [],
            $unparseable,
            'A file naming the option-key contract must parse, or the population sweep silently misses it',
        );
    }

    /**
     * Answering the contract is the whole subject of this file: a class that
     * implements the interface without a body of its own for
     * `acceptedOptionKeys()` cannot be caught by the compiler once any
     * intermediate class supplies one, and would then declare its parent's key
     * set as if it were its own.
     */
    #[Test]
    public function itFindsEveryImplementationAnsweringForItsOwnKeySet(): void
    {
        $population = self::population();

        self::assertNotSame([], $population, 'The sweep found no implementation at all — it is not looking where they live');

        $withoutOwnDeclaration = array_keys(array_filter($population, static fn(array $found): bool => !$found['declares']));

        self::assertSame([], $withoutOwnDeclaration, 'Every implementation states its own key set');
    }

    /**
     * The six anonymous fixtures are the half of the population no reflection
     * over class names can see. An assertion that the sweep still finds them
     * is what keeps a future "just use the classmap" simplification from
     * quietly halving the subject.
     */
    #[Test]
    public function itSeesTheAnonymousImplementationsAsWellAsTheNamedOnes(): void
    {
        $population = self::population();

        $anonymous = array_filter($population, static fn(array $found): bool => $found['anonymous']);
        $named = array_filter($population, static fn(array $found): bool => !$found['anonymous']);

        self::assertNotSame([], $anonymous, 'An anonymous implementation is still an implementation');
        self::assertNotSame([], $named);
    }

    /**
     * The second witness. The registry is assembled by the container and knows
     * nothing about the file sweep; a production options class missing from
     * either side is a defect of that side.
     */
    #[Test]
    public function itCoversEveryRegisteredRulesOptionsClass(): void
    {
        $population = self::population();

        foreach (self::registeredOptionsClasses() as $ruleName => $optionsClass) {
            self::assertArrayHasKey(
                $optionsClass,
                $population,
                \sprintf('Rule "%s" is registered with an options class the sweep never saw', $ruleName),
            );
        }
    }

    /**
     * A level slot answers for itself, so its class must be in the population
     * too — and must be the kind of class the factory is allowed to ask.
     */
    #[Test]
    public function itCoversEveryLevelSlotOfEveryHierarchicalRule(): void
    {
        $population = self::population();
        $hierarchical = self::hierarchicalOptionsClasses();

        self::assertNotSame([], $hierarchical, 'No hierarchical rule found — the depth-2 half of the walk has no subject');

        foreach ($hierarchical as $optionsClass) {
            foreach ($optionsClass::levelOptionsClasses() as $slot => $levelClass) {
                self::assertArrayHasKey($levelClass, $population, \sprintf('%s slot "%s"', $optionsClass, $slot));
                self::assertTrue(
                    is_a($levelClass, LevelOptionsInterface::class, true),
                    \sprintf('%s slot "%s" names a class the factory cannot ask for a key set', $optionsClass, $slot),
                );
            }
        }
    }

    /**
     * `levelOptionsClasses()` is the declaration and `getSupportedLevels()` is
     * the view of it. A slot present in one and absent from the other is either
     * a level nothing can configure or a slot nothing reports at.
     */
    #[Test]
    public function itNamesTheSameSlotsItSupportsLevels(): void
    {
        foreach (self::hierarchicalOptionsClasses() as $optionsClass) {
            $declared = array_keys($optionsClass::levelOptionsClasses());
            $options = $optionsClass::fromArray([]);
            self::assertInstanceOf(HierarchicalRuleOptionsInterface::class, $options);

            $supported = array_map(
                static fn(SymbolLevel $level): string => $level->value,
                $options->getSupportedLevels(),
            );

            sort($declared);
            sort($supported);

            self::assertSame($supported, $declared, $optionsClass);
        }
    }

    /**
     * The map names, for each slot, the class the rule really hands out for
     * that level.
     *
     * The depth-2 walk trusts `levelOptionsClasses()` alone for "which keys are
     * allowed inside this slot", while every other consumer of a level's
     * options goes through `forLevel()`. Two classes swapped between two slots
     * of one rule would keep both sides well formed and every existing
     * assertion green, and would then refuse a correct key in one slot and
     * accept a wrong one in the other — both in silence, since the refusal
     * quotes the vocabulary of whichever class the map named.
     */
    #[Test]
    public function itNamesForEachSlotTheClassTheRuleHandsOutForThatLevel(): void
    {
        $slots = 0;

        foreach (self::hierarchicalOptionsClasses() as $optionsClass) {
            $options = $optionsClass::fromArray([]);
            self::assertInstanceOf(HierarchicalRuleOptionsInterface::class, $options);

            foreach ($optionsClass::levelOptionsClasses() as $slot => $levelClass) {
                self::assertSame(
                    $levelClass,
                    $options->forLevel(SymbolLevel::from($slot))::class,
                    \sprintf('%s declares "%s" for slot "%s" but hands out another class for that level', $optionsClass, $levelClass, $slot),
                );
                ++$slots;
            }
        }

        self::assertGreaterThan(0, $slots);
    }

    /**
     * A slot name is also a key the user writes at depth 1, and the refusal at
     * that depth prints only what the class declared.
     *
     * The walk dispatches into a slot before it ever consults the key set, so a
     * slot missing from the declaration keeps working — and the sentence
     * refusing its neighbour then advises a set that omits it, sending a reader
     * away from the one key that would have fixed their file.
     */
    #[Test]
    public function itDeclaresEveryLevelSlotAmongTheKeysItAcceptsAtDepthOne(): void
    {
        foreach (self::hierarchicalOptionsClasses() as $optionsClass) {
            $accepted = $optionsClass::acceptedOptionKeys();

            foreach (array_keys($optionsClass::levelOptionsClasses()) as $slot) {
                self::assertTrue(
                    $accepted->accepts(ConfigKeySpelling::normalize((string) $slot)),
                    \sprintf('%s dispatches slot "%s" but does not offer it among the keys allowed here', $optionsClass, $slot),
                );
            }
        }
    }

    /**
     * The walk looks a slot up with the *folded* spelling of what the user
     * wrote, against a map keyed by the raw `SymbolLevel` values. That the two
     * meet at all is a property of today's five level names, every one of which
     * folds to itself — a two-word level would stop being dispatched, and every
     * key below it would go unwalked in silence.
     *
     * Pinned rather than repaired: the fold is a one-line change in the walk the
     * day the population stops being uniform, and this assertion is what names
     * that day.
     */
    #[Test]
    public function itKeepsEverySlotNameEqualToItsOwnFoldedSpelling(): void
    {
        foreach (self::hierarchicalOptionsClasses() as $optionsClass) {
            foreach (array_keys($optionsClass::levelOptionsClasses()) as $slot) {
                $slot = (string) $slot;

                self::assertSame(
                    $slot,
                    ConfigKeySpelling::normalize($slot),
                    \sprintf('%s names slot "%s", which the walk would look up under another spelling', $optionsClass, $slot),
                );
            }
        }
    }

    /**
     * The refusal prints the declared spelling verbatim as the fix to type. A
     * declaration written `maxWarning` still *compares* correctly — both sides
     * are folded — so nothing else in the tree notices that the sentence now
     * advises a spelling the conventions do not use.
     */
    #[Test]
    public function itDeclaresEveryKeyInTheCanonicalKebabSpelling(): void
    {
        foreach (self::namedImplementations() as $class) {
            foreach ($class::acceptedOptionKeys()->acceptedForDisplay() as $spelling) {
                self::assertMatchesRegularExpression(
                    '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/',
                    $spelling,
                    \sprintf('%s declares "%s" outside the canonical kebab spelling', $class, $spelling),
                );
            }
        }
    }

    /**
     * Pairs #27–#36. `threshold` is read through `ThresholdParser::parse()`'s
     * default argument, named by no constructor of any level class, and
     * documented — the one key a declaration transcribed from constructor
     * parameters would drop in all ten slots at once.
     */
    #[Test]
    public function itAcceptsTheThresholdKeyInEveryLevelSlot(): void
    {
        $slots = 0;

        foreach (self::hierarchicalOptionsClasses() as $optionsClass) {
            foreach ($optionsClass::levelOptionsClasses() as $slot => $levelClass) {
                self::assertTrue(
                    $levelClass::acceptedOptionKeys()->accepts('threshold'),
                    \sprintf('%s slot "%s" would refuse the documented threshold shorthand', $optionsClass, $slot),
                );
                ++$slots;
            }
        }

        self::assertGreaterThan(0, $slots);
    }

    /**
     * The set prints one spelling and compares another: the refusal advises
     * kebab, the walk asks in the folded spelling every door produces. A
     * printed key that does not fold back into the same set is advice for a
     * key that would then be refused — the exact loop the measurement found
     * the old warning caught in.
     */
    #[Test]
    public function itPrintsOnlySpellingsThatFoldBackIntoTheSameSet(): void
    {
        foreach (self::namedImplementations() as $class) {
            $set = $class::acceptedOptionKeys();

            foreach ($set->acceptedForDisplay() as $spelling) {
                $folded = ConfigKeySpelling::normalize($spelling);

                self::assertTrue($set->accepts($folded), \sprintf('%s prints "%s", which folds to "%s" and is then not accepted', $class, $spelling, $folded));
                self::assertTrue($set->knows($folded), \sprintf('%s prints "%s" but does not know it', $class, $spelling));
            }
        }
    }

    /**
     * The sweep, memoised: parsing the two trees once per process is what makes
     * a per-class assertion affordable.
     *
     * @return array<string, array{declares: bool, anonymous: bool}> class name (or `file:line` for an anonymous class) => what was found
     */
    private static function population(): array
    {
        /** @var array<string, array{declares: bool, anonymous: bool}>|null $population */
        static $population = null;

        if ($population !== null) {
            return $population;
        }

        $finder = new NodeFinder();
        $population = [];

        foreach (self::candidateFiles() as $file => $code) {
            $ast = self::parse($code);

            if ($ast === null) {
                continue;
            }

            $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);

            foreach ($finder->findInstanceOf($ast, Class_::class) as $node) {
                if (!self::implementsTheContract($node)) {
                    continue;
                }

                $declares = false;
                foreach ($finder->findInstanceOf($node, ClassMethod::class) as $method) {
                    if ($method->name->toString() === 'acceptedOptionKeys' && $method->isStatic()) {
                        $declares = true;
                    }
                }

                $anonymous = $node->name === null;
                $identity = $anonymous
                    ? \sprintf('%s:%d', $file, $node->getStartLine())
                    : ($node->namespacedName?->toString() ?? $node->name->toString());

                $population[$identity] = ['declares' => $declares, 'anonymous' => $anonymous];
            }
        }

        return $population;
    }

    /**
     * The named half, as class strings ready to be asked.
     *
     * @return list<class-string<RuleOptionsInterface|LevelOptionsInterface>>
     */
    private static function namedImplementations(): array
    {
        $named = [];

        foreach (self::population() as $identity => $found) {
            if ($found['anonymous'] || !class_exists($identity)) {
                continue;
            }

            /** @var class-string<RuleOptionsInterface|LevelOptionsInterface> $identity */
            $named[] = $identity;
        }

        return $named;
    }

    /**
     * @return array<string, class-string<RuleOptionsInterface>> rule name => options class
     */
    private static function registeredOptionsClasses(): array
    {
        /** @var array<string, class-string<RuleOptionsInterface>>|null $registered */
        static $registered = null;

        if ($registered !== null) {
            return $registered;
        }

        $execution = (new ContainerFactory())->create()->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $execution);

        $registered = [];
        foreach ($execution->allRules() as $rule) {
            $registered[$rule->name] = $rule->optionsClass;
        }

        return $registered;
    }

    /**
     * @return list<class-string<HierarchicalRuleOptionsInterface>>
     */
    private static function hierarchicalOptionsClasses(): array
    {
        $hierarchical = [];

        foreach (self::registeredOptionsClasses() as $optionsClass) {
            if (is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true)) {
                $hierarchical[$optionsClass] = $optionsClass;
            }
        }

        return array_values($hierarchical);
    }

    /**
     * Files that cannot be parsed *and* name the contract. The tree carries
     * fixtures that are invalid PHP on purpose, so a parse failure is not by
     * itself a defect — a parse failure in a file that could hold an
     * implementation is.
     *
     * @return list<string>
     */
    private static function unparseableFilesNamingTheContract(): array
    {
        $unparseable = [];

        foreach (self::candidateFiles() as $file => $code) {
            if (self::parse($code) === null) {
                $unparseable[] = $file;
            }
        }

        return $unparseable;
    }

    /**
     * Every file whose text mentions the contract at all — a class cannot
     * implement an interface without naming it or its alias in the same file,
     * so this prefilter cannot hide an implementation, and it keeps the sweep
     * to under a second.
     *
     * @return array<string, string> path relative to the project root => source
     */
    private static function candidateFiles(): array
    {
        /** @var array<string, string>|null $files */
        static $files = null;

        if ($files !== null) {
            return $files;
        }

        $root = \dirname(__DIR__, 5);
        $files = [];

        foreach (['src', 'tests'] as $tree) {
            /** @var iterable<SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $tree));

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = file_get_contents($file->getPathname());

                if ($code === false || !str_contains($code, 'OptionsInterface')) {
                    continue;
                }

                $files[substr($file->getPathname(), \strlen($root) + 1)] = $code;
            }
        }

        return $files;
    }

    /**
     * @return array<\PhpParser\Node\Stmt>|null null when the file is not valid PHP
     */
    private static function parse(string $code): ?array
    {
        /** @var Parser|null $parser */
        static $parser = null;
        $parser ??= (new ParserFactory())->createForNewestSupportedVersion();

        try {
            return $parser->parse($code);
        } catch (\PhpParser\Error) {
            return null;
        }
    }

    private static function implementsTheContract(Class_ $node): bool
    {
        foreach ($node->implements as $interface) {
            $name = $interface->toString();

            if (!interface_exists($name)) {
                continue;
            }

            if (is_a($name, RuleOptionsInterface::class, true) || is_a($name, LevelOptionsInterface::class, true)) {
                return true;
            }
        }

        return false;
    }
}
