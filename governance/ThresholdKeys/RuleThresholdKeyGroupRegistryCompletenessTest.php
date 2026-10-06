<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ThresholdKeys;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionShapeMatcher;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Checks call counts per path and membership of literal bandFor(self::class,
 * key) arguments. Repeated references to one declared band are not a bijection.
 * Tree discovery independently closes the reader population; the drift test
 * separately observes real builder effects for every accepted spelling,
 * including keys a repeated reference would otherwise leave unused.
 */
#[CoversClass(RuleOptionSurface::class)]
final class RuleThresholdKeyGroupRegistryCompletenessTest extends TestCase
{
    /** @var list<string> Roots whose short form targets only the callable band. */
    private const array LONE_THRESHOLD_EXCEPTIONS = [
        'complexity.ccn::',
        'complexity.cognitive::',
        'complexity.npath::',
    ];

    // ------------------------------------------------------------------
    // 1. declared bands agree with call sites' literal key arguments,
    //    both ways, matched by normalized threshold key (not position —
    //    a path can carry more than one group, e.g. long-parameter-list).
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideCallSitePaths(): iterable
    {
        foreach (self::discoverCallSites() as $key => $entry) {
            yield $key => [$entry['ruleName'], $entry['path'], \count($entry['groups'])];
        }
    }

    #[Test]
    #[DataProvider('provideCallSitePaths')]
    public function itAgreesWithEveryCallSitesLiteralKeys(string $ruleName, string $path, int $callSiteGroupCount): void
    {
        $callSiteGroups = self::discoverCallSites()[$ruleName . '::' . $path]['groups'];
        $registryGroups = ThresholdRuleDiscovery::registeredGroups()[$ruleName][$path] ?? [];

        self::assertCount(
            $callSiteGroupCount,
            $registryGroups,
            \sprintf(
                'Rule "%s" (path %s) has %d ThresholdParser::parse() call site group(s) but the declaration names %d.',
                $ruleName,
                $path === '' ? '(top level)' : $path,
                $callSiteGroupCount,
                \count($registryGroups),
            ),
        );

        foreach ($callSiteGroups as $callSiteGroup) {
            $matchingRegistryGroup = self::findByNormalizedThresholdKey($registryGroups, $callSiteGroup['threshold']);

            self::assertNotNull(
                $matchingRegistryGroup,
                \sprintf(
                    'Rule "%s" (path %s): no declared group has a threshold key normalizing to any of [%s] —'
                    . ' the call site\'s threshold spelling drifted away from every declared group.',
                    $ruleName,
                    $path === '' ? '(top level)' : $path,
                    implode(', ', $callSiteGroup['threshold']),
                ),
            );

            foreach (['warning', 'error', 'threshold'] as $role) {
                foreach ($callSiteGroup[$role] as $literalKey) {
                    $normalized = ConfigKeySpelling::normalize($literalKey);
                    $declaredNormalized = array_map(ConfigKeySpelling::normalize(...), $matchingRegistryGroup[$role]);

                    self::assertContains(
                        $normalized,
                        $declaredNormalized,
                        \sprintf(
                            'Rule "%s" (path %s): call site names "%s" (role %s) but the matching declared group'
                            . ' declares only [%s] for that role.',
                            $ruleName,
                            $path === '' ? '(top level)' : $path,
                            $literalKey,
                            $role,
                            implode(', ', $matchingRegistryGroup[$role]),
                        ),
                    );
                }
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRegistryEntries(): iterable
    {
        foreach (ThresholdRuleDiscovery::registeredGroups() as $ruleName => $byPath) {
            foreach (array_keys($byPath) as $path) {
                yield $ruleName . '::' . $path => [$ruleName, $path];
            }
        }
    }

    #[Test]
    #[DataProvider('provideRegistryEntries')]
    public function itMakesEveryRegistryEntryCorrespondToARealCallSite(string $ruleName, string $path): void
    {
        $callSites = self::discoverCallSites();

        self::assertArrayHasKey(
            $ruleName . '::' . $path,
            $callSites,
            \sprintf(
                'RuleOptionSurface declares an entry for rule "%s" (path %s) that no real'
                . ' ThresholdParser::parse() call site corresponds to — remove the stray entry.',
                $ruleName,
                $path === '' ? '(top level)' : $path,
            ),
        );
    }

    // ------------------------------------------------------------------
    // 2. the LONE_THRESHOLD exception is declared and stays true.
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLoneThresholdExceptions(): iterable
    {
        foreach (self::LONE_THRESHOLD_EXCEPTIONS as $key) {
            [$ruleName, $path] = explode('::', $key, 2);
            yield $key => [$ruleName, $path];
        }
    }

    #[Test]
    #[DataProvider('provideLoneThresholdExceptions')]
    public function itKeepsTheRootShortFormScopedToTheCallableBand(string $ruleName, string $path): void
    {
        $class = ThresholdRuleDiscovery::ruleNameToOptionsClass()[$ruleName];
        if (!is_a($class, RuleOptionsInterface::class, true)) {
            self::fail('The root must declare rule options.');
        }
        $set = RuleOptionSurface::declaredFor($class);
        self::assertSame(['callable.threshold'], $set->spreading()['threshold']);
        self::assertFalse($set->knows('warning'));
        self::assertFalse($set->knows('error'));
        self::assertArrayHasKey($ruleName . '::callable', self::discoverCallSites());
    }

    #[Test]
    public function itRequiresEveryDeclaredBandToHaveBothWritableHalves(): void
    {
        foreach (ThresholdRuleDiscovery::registeredGroups() as $byPath) {
            foreach ($byPath as $groups) {
                foreach ($groups as $group) {
                    self::assertNotSame([], $group['warning']);
                    self::assertNotSame([], $group['error']);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // 3. every writable key is accepted, same form as its group's threshold.
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideWritableGroups(): iterable
    {
        foreach (ThresholdRuleDiscovery::registeredGroups() as $ruleName => $byPath) {
            foreach ($byPath as $path => $groups) {
                foreach (array_keys($groups) as $index) {
                    if ($groups[$index]['warning'] === []) {
                        // LONE_THRESHOLD: nothing is ever written by unfolding here.
                        continue;
                    }

                    yield \sprintf('%s::%s#%d', $ruleName, $path === '' ? '(top)' : $path, $index) => [$ruleName, $path, $index];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('provideWritableGroups')]
    public function itKeepsEveryWritableKeyAcceptedAndSameFormAsThreshold(string $ruleName, string $path, int $groupIndex): void
    {
        $group = ThresholdRuleDiscovery::registeredGroups()[$ruleName][$path][$groupIndex];
        $acceptedHere = self::acceptedKeysAt($ruleName, $path);

        foreach (['warning', 'error', 'threshold'] as $role) {
            foreach ($group[$role] as $literalKey) {
                $normalized = ConfigKeySpelling::normalize($literalKey);

                self::assertTrue(
                    $acceptedHere->knows($normalized),
                    \sprintf(
                        'Rule "%s" (path %s): declared group writes/reads "%s" (role %s) but the real options'
                        . ' class does not accept it.',
                        $ruleName,
                        $path === '' ? '(top level)' : $path,
                        $literalKey,
                        $role,
                    ),
                );

                $shape = $acceptedHere->shapeOf($normalized);
                self::assertNotNull($shape);

                self::assertSame(
                    $group['form'] === RuleOptionValueForm::Number,
                    (new RuleOptionShapeMatcher())->matches($shape, 1.5),
                    \sprintf(
                        'Rule "%s" (path %s): key "%s" (role %s) accepts a fraction iff the group\'s declared'
                        . ' form is Number — real declaration and registry disagree.',
                        $ruleName,
                        $path === '' ? '(top level)' : $path,
                        $literalKey,
                        $role,
                    ),
                );

                self::assertTrue(
                    (new RuleOptionShapeMatcher())->matches($shape, 1),
                    \sprintf(
                        'Rule "%s" (path %s): key "%s" (role %s) must at least accept a whole number.',
                        $ruleName,
                        $path === '' ? '(top level)' : $path,
                        $literalKey,
                        $role,
                    ),
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // 4/5. the denominator itself: every rule class is discovered from the
    //    tree (see discoverRuleClasses()), and no ThresholdParser::parse()
    //    call site anywhere in src/ escapes the files that discovery visits.
    // ------------------------------------------------------------------

    /**
     * Closes the gap a hand-typed list of "the roots rules live under" would
     * otherwise leave open: {@see self::discoverCallSites()} only ever looks
     * at the source files of options classes reachable from
     * {@see ThresholdRuleDiscovery::ruleClasses()} — a rule whose Options class lived
     * outside whatever that discovery visits would be invisible to every
     * other assertion in this file without ever failing one. This test asks
     * the opposite question directly, over the whole of `src/`: does any
     * `ThresholdParser::parse()` call site exist in a file this guard never
     * looked at?
     */
    #[Test]
    public function itLeavesNoThresholdParserCallSiteOutsideTheDiscoveredOptionsClasses(): void
    {
        $consideredFiles = [];
        foreach (ThresholdRuleDiscovery::ruleNameToOptionsClass() as $optionsClass) {
            foreach (ThresholdRuleDiscovery::sourceFilesByPath($optionsClass) as $sourceFile) {
                $consideredFiles[$sourceFile] = true;
            }
        }

        $escaped = [];
        $finder = (new Finder())->files()->in(ThresholdRuleDiscovery::srcDir())->name('*.php');

        foreach ($finder as $file) {
            $path = ThresholdRuleDiscovery::realOrPathname($file);

            if (isset($consideredFiles[$path])) {
                continue;
            }

            if (self::extractCallSiteGroups($path) !== []) {
                $escaped[] = $path;
            }
        }

        self::assertSame(
            [],
            $escaped,
            \sprintf(
                'These file(s) call ThresholdParser::parse() but are not the source file of any options class'
                . ' this guard discovered from a real rule — it cannot see whether their keys match the declaration: %s',
                implode(', ', $escaped),
            ),
        );
    }

    // ------------------------------------------------------------------
    // Shared discovery — mirrors RuleThresholdKeyGroupRegistryDriftTest's
    // rule-class discovery (same technique, independent implementation is
    // not the point here: THIS file's independence comes from reading call
    // site ARGUMENTS via an AST rather than counting occurrences).
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{ruleName: string, path: string, groups: list<array{warning: list<string>, error: list<string>, threshold: list<string>}>}>
     */
    private static function discoverCallSites(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $result = [];

        foreach (ThresholdRuleDiscovery::ruleNameToOptionsClass() as $ruleName => $optionsClass) {
            foreach (ThresholdRuleDiscovery::sourceFilesByPath($optionsClass) as $path => $sourceFile) {
                $groups = self::extractCallSiteGroups($sourceFile);
                if ($groups === []) {
                    continue;
                }

                $result[$ruleName . '::' . $path] = [
                    'ruleName' => $ruleName,
                    'path' => $path,
                    'groups' => $groups,
                ];
            }
        }

        return $cache = $result;
    }

    /**
     * Parses one source file and returns one entry per
     * `ThresholdParser::parse(...)` call found in it, with its literal key
     * arguments — never derived from the declaration.
     *
     * @return list<array{warning: list<string>, error: list<string>, threshold: list<string>}>
     */
    private static function extractCallSiteGroups(string $sourceFile): array
    {
        $source = file_get_contents($sourceFile);
        if ($source === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $sourceFile));
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($source) ?? [];

        $finder = new NodeFinder();
        /** @var list<Node\Expr\StaticCall> $calls */
        $calls = $finder->find($ast, static fn(Node $node): bool => $node instanceof Node\Expr\StaticCall
                && $node->class instanceof Node\Name
                && strtolower($node->class->getLast()) === 'thresholdparser'
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'parse');

        $groups = [];
        foreach ($calls as $call) {
            $relative = substr($sourceFile, \strlen(ThresholdRuleDiscovery::srcDir()) + 1, -4);
            $optionsClass = 'Qualimetrix\\' . str_replace('/', '\\', $relative);
            if (!is_a($optionsClass, RuleOptionsInterface::class, true) && !is_a($optionsClass, LevelOptionsInterface::class, true)) {
                throw new RuntimeException('A threshold reader is outside the declared options population.');
            }
            $groups[] = self::extractOneCall($call, $optionsClass);
        }

        return $groups;
    }

    /**
     * @return array{warning: list<string>, error: list<string>, threshold: list<string>}
     */
    private static function extractOneCall(Node\Expr\StaticCall $call, string $optionsClass): array
    {
        $argument = $call->args[1] ?? null;
        $lookup = $argument instanceof Node\Arg ? $argument->value : null;
        if (!$lookup instanceof Node\Expr\StaticCall || !$lookup->class instanceof Node\Name
            || $lookup->class->getLast() !== 'RuleOptionSurface' || !$lookup->name instanceof Node\Identifier
            || $lookup->name->toString() !== 'bandFor') {
            throw new RuntimeException('A threshold read must name its declared band explicitly.');
        }
        $classArgument = $lookup->args[0] ?? null;
        $class = $classArgument instanceof Node\Arg ? $classArgument->value : null;
        if (!$class instanceof Node\Expr\ClassConstFetch || !$class->class instanceof Node\Name
            || $class->class->toLowerString() !== 'self' || !$class->name instanceof Node\Identifier
            || $class->name->toLowerString() !== 'class') {
            throw new RuntimeException('A threshold read must look up the band of self::class.');
        }
        $nameArgument = $lookup->args[1] ?? null;
        if (!$nameArgument instanceof Node\Arg || !$nameArgument->value instanceof Node\Scalar\String_) {
            throw new RuntimeException('This guard cannot resolve a band lookup without its literal shorthand.');
        }
        if (!is_a($optionsClass, RuleOptionsInterface::class, true) && !is_a($optionsClass, LevelOptionsInterface::class, true)) {
            throw new RuntimeException('The band reader must implement an options contract.');
        }
        $band = RuleOptionSurface::bandFor($optionsClass, $nameArgument->value->value);
        return ['warning' => [$band->warning], 'error' => [$band->error], 'threshold' => [$band->shorthand]];
    }

    private static function acceptedKeysAt(string $ruleName, string $path): RuleOptionKeySet
    {
        $optionsClass = ThresholdRuleDiscovery::ruleNameToOptionsClass()[$ruleName];

        if ($path === '') {
            if (!is_a($optionsClass, RuleOptionsInterface::class, true)) {
                throw new RuntimeException('A root must declare rule options.');
            }
            return RuleOptionSurface::declaredFor($optionsClass);
        }

        \assert(is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true));
        $levelClasses = $optionsClass::levelOptionsClasses();

        return RuleOptionSurface::declaredFor($levelClasses[$path]);
    }

    /**
     * @param list<array{warning: list<string>, error: list<string>, threshold: list<string>}> $registryGroups
     * @param list<string> $callSiteThresholdKeys
     *
     * @return array{warning: list<string>, error: list<string>, threshold: list<string>}|null
     */
    private static function findByNormalizedThresholdKey(array $registryGroups, array $callSiteThresholdKeys): ?array
    {
        $normalizedCallSiteThresholdKeys = array_map(ConfigKeySpelling::normalize(...), $callSiteThresholdKeys);

        foreach ($registryGroups as $group) {
            $normalizedRegistryThresholdKeys = array_map(ConfigKeySpelling::normalize(...), $group['threshold']);

            if (array_intersect($normalizedCallSiteThresholdKeys, $normalizedRegistryThresholdKeys) !== []) {
                return $group;
            }
        }

        return null;
    }
}
