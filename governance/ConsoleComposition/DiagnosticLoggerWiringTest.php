<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConsoleComposition;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Nothing that takes a logger is left to decide for itself that it has none.
 *
 * `LoggerInterface $logger = new NullLogger()` is the right signature — a class
 * built directly in a test should not have to be handed a logger — and it is
 * also a defect generator, because the wiring that forgets the argument looks
 * exactly like the wiring that never needed one. Three services shipped mute
 * that way in one stage (`ReportingGitScopeQuery`, `ComposerAutoloadMap`,
 * `CacheKeyGenerator`), each found by a person reading a diff rather than by a
 * run.
 *
 * **What the first case really guards is one string.** `registerAliasForArgument()`
 * keys its alias by *parameter name*, and `CoreServicesConfigurator` passes
 * `'logger'` to say which. Left to default, the name is `delegatingLogger`,
 * which no constructor in this tree declares — autowiring then matches nothing
 * and every holder quietly keeps its signature default. That is how all three
 * shipped mute. The predicate is therefore about the outcome and not the
 * spelling: whatever a registration does, the argument at the logger's position
 * must be bound to the owner by the time the container is compiled.
 *
 * **Why definitions and not instances.** {@see ErrorStreamContainerIdentityTest}
 * can walk the composed object graph because a holder keeps its stream in a
 * property. `CacheKeyGenerator` does not keep its logger: it warns in the
 * constructor and drops it. No object walk can see that argument, so the
 * measurement is taken where the argument is written.
 *
 * **What a green run here does not prove.** Reaching the user is a longer
 * chain than being handed the owner — {@see \Qualimetrix\Tests\Infrastructure\Git\Integration\GitDiagnosticWiringTest}
 * runs the binary for two of these and reads its stderr, which is the half a
 * container assertion cannot cover. And a class the composed container never
 * builds is outside the first case entirely; that is what the second one is
 * for, and its own population is production `new` sites, so a holder
 * constructed by neither is seen by neither.
 */
#[CoversNothing]
final class DiagnosticLoggerWiringTest extends TestCase
{
    /**
     * Every class the composed run builds with a logger parameter.
     *
     * An enumeration, not a floor, for the reason the neighbouring identity
     * guard gives: a holder that leaves the graph is as much a change to who
     * gets told as one that joins it. It also keeps a walk that silently found
     * nothing from passing as a walk that found nothing wrong.
     *
     * @var list<class-string>
     */
    private const array COMPOSED_HOLDERS = [
        'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Evaluation\\ComputedMetricEvaluator',
        'Qualimetrix\\Analysis\\Evidence\\Coupling\\DistanceRule',
        'Qualimetrix\\Analysis\\Evidence\\Design\\Inheritance\\DitGlobalCollector',
        'Qualimetrix\\Analysis\\Evidence\\Duplication\\DuplicationDetector',
        'Qualimetrix\\Analysis\\Evidence\\Measurement\\Aggregation\\MeasurementAggregationService',
        'Qualimetrix\\Analysis\\Run\\Collection\\CollectionOrchestrator',
        'Qualimetrix\\Analysis\\Run\\Pipeline\\AnalysisPipeline',
        'Qualimetrix\\Infrastructure\\Ast\\PhpFileParser',
        'Qualimetrix\\Infrastructure\\Cache\\CacheKeyGenerator',
        'Qualimetrix\\Infrastructure\\Composer\\ComposerAutoloadMap',
        'Qualimetrix\\Infrastructure\\Console\\Command\\GraphExportCommand',
        'Qualimetrix\\Infrastructure\\Git\\GitScopeResolver',
        'Qualimetrix\\Infrastructure\\Git\\ReportingGitScopeQuery',
        'Qualimetrix\\Infrastructure\\Parallel\\Strategy\\AmphpParallelStrategy',
        'Qualimetrix\\Infrastructure\\Parallel\\Strategy\\StrategySelector',
    ];

    #[Test]
    public function itBindsTheRunsLoggerWhereverTheContainerBuildsAHolder(): void
    {
        $bindings = [];
        $visited = [];

        foreach ((new ContainerFactory())->create()->getDefinitions() as $id => $definition) {
            self::collectLoggerBindings((string) $id, $definition, $bindings, $visited);
        }

        ksort($bindings);

        self::assertSame(
            self::COMPOSED_HOLDERS,
            array_keys($bindings),
            'the set of services built with a logger changed; say who gained or lost a voice',
        );

        $owner = 'a reference to ' . DelegatingLogger::class;
        $mute = [];

        foreach ($bindings as $class => $binding) {
            if ($binding !== $owner) {
                $mute[] = $class . ' <- ' . $binding;
            }
        }

        self::assertSame(
            [],
            $mute,
            'these are registered without the run\'s logger, so what they report is heard by nobody. Autowire the'
            . ' definition, or name the argument: ->setArgument(\'$logger\', new Reference(DelegatingLogger::class))',
        );
    }

    /**
     * The half the container cannot see: production code building a holder itself.
     *
     * A worker builds its own parser and key generator because it has no
     * container, and both signatures default to silence. Silence may well be
     * the right answer there — it is written out so that it is an answer.
     */
    #[Test]
    public function itLeavesNoProductionConstructionSiteOnTheSignatureDefault(): void
    {
        $holders = self::classesWithADefaultedLoggerParameter();
        self::assertNotSame([], $holders, 'the sweep found no class to check, which is not the same as no defect');

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $omissions = [];
        $sites = 0;

        foreach (self::productionFiles() as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                throw new RuntimeException('Cannot read ' . $file);
            }

            $statements = $parser->parse($source);

            if ($statements === null) {
                throw new RuntimeException('Cannot parse ' . $file);
            }

            $traverser = new NodeTraverser(new NameResolver());
            $statements = $traverser->traverse($statements);

            /** @var list<New_> $expressions */
            $expressions = (new NodeFinder())->findInstanceOf($statements, New_::class);

            foreach ($expressions as $expression) {
                if (!$expression->class instanceof Name) {
                    continue;
                }

                $class = $expression->class->toString();

                if (!isset($holders[$class])) {
                    continue;
                }

                ++$sites;
                $position = $holders[$class];
                $written = false;

                foreach ($expression->args as $index => $argument) {
                    if (!$argument instanceof Node\Arg) {
                        continue;
                    }

                    $written = $written
                        || $argument->name?->toString() === $position['name']
                        || ($argument->name === null && $index === $position['index']);
                }

                if (!$written) {
                    $omissions[] = \sprintf(
                        '%s:%d new %s() without $%s',
                        substr($file, \strlen(self::repositoryRoot()) + 1),
                        $expression->getStartLine(),
                        $class,
                        $position['name'],
                    );
                }
            }
        }

        self::assertGreaterThan(0, $sites, 'no production code builds a logger holder, so this proved nothing');
        self::assertSame(
            [],
            $omissions,
            'these take the NullLogger default by omission. Write the argument — `new NullLogger()` with the reason'
            . ' is a decision a reader can disagree with; an absent argument is not',
        );
    }

    /**
     * @param array<class-string, string> $bindings what each holder's logger argument is bound to
     * @param array<int, true> $visited
     *
     * @param-out array<class-string, string> $bindings
     * @param-out array<int, true> $visited
     */
    private static function collectLoggerBindings(
        string $trail,
        Definition $definition,
        array &$bindings,
        array &$visited,
    ): void {
        $identity = spl_object_id($definition);

        if (isset($visited[$identity])) {
            return;
        }

        $visited[$identity] = true;

        $class = $definition->getClass();

        if ($class !== null && class_exists($class)) {
            $constructor = (new ReflectionClass($class))->getConstructor();

            foreach ($constructor?->getParameters() ?? [] as $index => $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType || !is_a($type->getName(), LoggerInterface::class, true)) {
                    continue;
                }

                $arguments = $definition->getArguments();
                // Three spellings survive compilation: positional, and the
                // named one with and without its sigil.
                $bound = $arguments[$index]
                    ?? $arguments[$parameter->getName()]
                    ?? $arguments['$' . $parameter->getName()]
                    ?? null;
                $bindings[$class] = match (true) {
                    $bound instanceof Reference => 'a reference to ' . (string) $bound,
                    $bound === null => 'the signature default',
                    default => 'a ' . get_debug_type($bound),
                };
            }
        }

        // A private service is inlined into whoever uses it, and a factory
        // service is inlined into the factory call rather than the argument
        // list. `CacheKeyGenerator` reaches the run only through the second,
        // so a walk over top-level definitions alone would report it absent.
        $nested = [
            ...array_values($definition->getArguments()),
            ...array_values($definition->getProperties()),
        ];

        foreach ($definition->getMethodCalls() as $call) {
            $nested = [...$nested, ...array_values($call[1] ?? [])];
        }

        foreach ([$definition->getFactory(), $definition->getConfigurator()] as $callable) {
            $nested[] = \is_array($callable) ? ($callable[0] ?? null) : $callable;
        }

        foreach ($nested as $value) {
            foreach (\is_array($value) ? $value : [$value] as $candidate) {
                if ($candidate instanceof Definition) {
                    self::collectLoggerBindings($trail, $candidate, $bindings, $visited);
                }
            }
        }
    }

    /**
     * Production classes whose logger parameter may be omitted at a call site.
     *
     * A required parameter needs no guard: PHP refuses the call.
     *
     * @return array<class-string, array{index: int, name: string}>
     */
    private static function classesWithADefaultedLoggerParameter(): array
    {
        $holders = [];

        foreach (self::productionFiles() as $file) {
            $source = file_get_contents($file);

            if ($source === false
                || preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1
                || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $name) !== 1) {
                continue;
            }

            $class = trim($namespace[1]) . '\\' . $name[1];

            if (!class_exists($class)) {
                continue;
            }

            $constructor = (new ReflectionClass($class))->getConstructor();

            foreach ($constructor?->getParameters() ?? [] as $index => $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType
                    || !is_a($type->getName(), LoggerInterface::class, true)
                    || !$parameter->isDefaultValueAvailable()) {
                    continue;
                }

                $holders[$class] = ['index' => $index, 'name' => $parameter->getName()];
            }
        }

        return $holders;
    }

    /**
     * @return list<string>
     */
    private static function productionFiles(): array
    {
        $files = [];
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::repositoryRoot() . '/src'));

        foreach ($tree as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
