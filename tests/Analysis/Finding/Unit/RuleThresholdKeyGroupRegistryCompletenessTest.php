<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleThresholdKeyGroupRegistry;
use ReflectionClass;
use ReflectionClassConstant;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Proves {@see RuleThresholdKeyGroupRegistry} is complete, from two sources
 * that are neither each other nor the registry itself:
 *
 * - the `ThresholdParser::parse()` call sites and their LITERAL key
 *   arguments, read by parsing `src/` with `nikic/php-parser` (an AST witness
 *   rather than a token stream — the same "not the registry, not a hand
 *   list" property {@see RuleThresholdKeyGroupRegistryDriftTest} already
 *   uses for occurrence counting, applied to the arguments themselves rather
 *   than just their count);
 * - the join key `(ruleName, path) -> options class`, from the real rule
 *   class list via {@see RuleNameReader::read()} and the static
 *   `RuleDefinitionInterface::getOptionsClass()` /
 *   `HierarchicalRuleOptionsInterface::levelOptionsClasses()` — the same
 *   discovery {@see RuleThresholdKeyGroupRegistryDriftTest} already uses.
 *
 * Five things are asserted, none of them "the registry agrees with itself":
 *
 * 1. every call site's key arguments, normalized, appear in the registry
 *    entry for its `(ruleName, path)` and vice versa
 *    ({@see itAgreesWithEveryCallSitesLiteralKeys()});
 * 2. the three `LONE_THRESHOLD` pairs are a DECLARED exception — their call
 *    sites name `warning`/`error`, but the options class at that path does
 *    not accept them, so the omission is a fact about the class, not a
 *    silent gap ({@see itKeepsTheLoneThresholdExceptionTrue()}), and every
 *    registry group with no warning/error keys IS one of these declared
 *    exceptions — not merely a superset of them
 *    ({@see itDeclaresLoneThresholdExceptionsForEveryEmptyPairGroup()});
 * 3. every key a registry entry can WRITE by unfolding is accepted by the
 *    options class at that path, and declared there with the SAME scalar
 *    form as the group's own `threshold` key
 *    ({@see itKeepsEveryWritableKeyAcceptedAndSameFormAsThreshold()}) — the
 *    condition that makes unfolding safe against the recognition seam, which
 *    judges an unfolded key exactly as a written one;
 * 4. the universe both sides above compare against — every real rule class
 *    this codebase ships — is itself discovered from the tree
 *    ({@see self::discoverRuleClasses()} scans the whole of `src/`), not
 *    named by a hand-typed list of roots that could omit a real one;
 * 5. no `ThresholdParser::parse()` call site anywhere in `src/` lies outside
 *    the set of files the discovery above actually visited
 *    ({@see itLeavesNoThresholdParserCallSiteOutsideTheDiscoveredOptionsClasses()})
 *    — closing the gap a hand-typed root list would otherwise leave: a rule
 *    with a call site outside the considered files would be invisible to
 *    every assertion above without this one saying so.
 *
 * This test does not re-derive `RuleThresholdKeyGroupRegistryDriftTest`'s
 * behavioural probe (does the key actually reach `Options::fromArray()`);
 * that remains the third, complementary witness.
 */
#[CoversClass(RuleThresholdKeyGroupRegistry::class)]
final class RuleThresholdKeyGroupRegistryCompletenessTest extends TestCase
{
    /**
     * `(ruleName, path)` pairs whose call site names `warning`/`error` but
     * whose options class refuses them at the option-key seam before any
     * merge happens — the declared `LONE_THRESHOLD` exception. Reason:
     * `acceptedOptionKeys()` at that path admits only `enabled`/`threshold`.
     *
     * @var list<string>
     */
    private const array LONE_THRESHOLD_EXCEPTIONS = [
        'complexity.ccn::',
        'complexity.cognitive::',
        'complexity.npath::',
    ];

    // ------------------------------------------------------------------
    // 1. registry entries agree with call sites' literal key arguments,
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
        $registryGroups = RuleThresholdKeyGroupRegistry::groupsFor($ruleName, $path);

        self::assertCount(
            $callSiteGroupCount,
            $registryGroups,
            \sprintf(
                'Rule "%s" (path %s) has %d ThresholdParser::parse() call site group(s) but the registry declares %d.',
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
                    'Rule "%s" (path %s): no registry group has a threshold key normalizing to any of [%s] —'
                    . ' the call site\'s threshold spelling drifted away from every declared group.',
                    $ruleName,
                    $path === '' ? '(top level)' : $path,
                    implode(', ', $callSiteGroup['threshold']),
                ),
            );

            foreach (['warning', 'error', 'threshold'] as $role) {
                if ($role !== 'threshold' && $matchingRegistryGroup['warning'] === [] && $matchingRegistryGroup['error'] === []) {
                    // The declared LONE_THRESHOLD exception: the call site
                    // names warning/error, but the registry deliberately
                    // omits them — see itKeepsTheLoneThresholdExceptionTrue().
                    continue;
                }

                foreach ($callSiteGroup[$role] as $literalKey) {
                    $normalized = ConfigKeySpelling::normalize($literalKey);
                    $declaredNormalized = array_map(ConfigKeySpelling::normalize(...), $matchingRegistryGroup[$role]);

                    self::assertContains(
                        $normalized,
                        $declaredNormalized,
                        \sprintf(
                            'Rule "%s" (path %s): call site names "%s" (role %s) but the matching registry group'
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
        foreach (self::readRegisteredGroups() as $ruleName => $byPath) {
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
                'RuleThresholdKeyGroupRegistry declares an entry for rule "%s" (path %s) that no real'
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
    public function itKeepsTheLoneThresholdExceptionTrue(string $ruleName, string $path): void
    {
        $callSite = self::discoverCallSites()[$ruleName . '::' . $path] ?? null;
        self::assertNotNull($callSite, \sprintf('No call site found for declared exception "%s::%s".', $ruleName, $path));

        $registryGroups = RuleThresholdKeyGroupRegistry::groupsFor($ruleName, $path);
        self::assertCount(1, $registryGroups, 'A LONE_THRESHOLD exception must declare exactly one group.');
        self::assertSame([], $registryGroups[0]['warning'], 'A LONE_THRESHOLD group must declare no warning key.');
        self::assertSame([], $registryGroups[0]['error'], 'A LONE_THRESHOLD group must declare no error key.');

        // The call site itself DOES name warning/error (that's what makes this
        // an exception rather than a plain gap) — the options class at this
        // path must be the reason they are unreachable, not an oversight.
        $callSiteGroup = $callSite['groups'][0];
        self::assertNotSame([], $callSiteGroup['warning'], 'Declared exception\'s call site must actually name a warning key — otherwise it is not this exception.');

        $optionsClass = self::ruleNameToOptionsClass()[$ruleName];
        $acceptedHere = $optionsClass::acceptedOptionKeys();

        foreach ($callSiteGroup['warning'] as $literalKey) {
            self::assertFalse(
                $acceptedHere->knows(ConfigKeySpelling::normalize($literalKey)),
                \sprintf(
                    'The LONE_THRESHOLD exception for "%s" (path %s) no longer holds: %s::acceptedOptionKeys()'
                    . ' now accepts "%s" — this pair no longer belongs in the exception list and needs a real'
                    . ' registry group.',
                    $ruleName,
                    $path === '' ? '(top level)' : $path,
                    $optionsClass,
                    $literalKey,
                ),
            );
        }

        foreach ($callSiteGroup['error'] as $literalKey) {
            self::assertFalse($acceptedHere->knows(ConfigKeySpelling::normalize($literalKey)));
        }
    }

    /**
     * The reverse of {@see itKeepsTheLoneThresholdExceptionTrue()}: that test
     * only checks the exception list is still TRUE for the three entries it
     * names; it says nothing about a FOURTH registry group with empty
     * `warning`/`error` that nobody added to the list. Such a group would be
     * silently skipped by every provider above (`provideWritableGroups()`
     * excludes it, and the LONE_THRESHOLD branch of
     * `itAgreesWithEveryCallSitesLiteralKeys()` excuses it) without this
     * assertion ever having named it as a declared exception.
     */
    #[Test]
    public function itDeclaresLoneThresholdExceptionsForEveryEmptyPairGroup(): void
    {
        foreach (self::readRegisteredGroups() as $ruleName => $byPath) {
            foreach ($byPath as $path => $groups) {
                foreach ($groups as $group) {
                    if ($group['warning'] !== [] || $group['error'] !== []) {
                        continue;
                    }

                    self::assertContains(
                        $ruleName . '::' . $path,
                        self::LONE_THRESHOLD_EXCEPTIONS,
                        \sprintf(
                            'Registry group for rule "%s" (path %s) declares no warning/error keys but is'
                            . ' not in LONE_THRESHOLD_EXCEPTIONS — add it there, or give it a real graduated pair.',
                            $ruleName,
                            $path === '' ? '(top level)' : $path,
                        ),
                    );
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
        foreach (self::readRegisteredGroups() as $ruleName => $byPath) {
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
        $group = RuleThresholdKeyGroupRegistry::groupsFor($ruleName, $path)[$groupIndex];
        $acceptedHere = self::acceptedKeysAt($ruleName, $path);

        foreach (['warning', 'error', 'threshold'] as $role) {
            foreach ($group[$role] as $literalKey) {
                $normalized = ConfigKeySpelling::normalize($literalKey);

                self::assertTrue(
                    $acceptedHere->knows($normalized),
                    \sprintf(
                        'Rule "%s" (path %s): registry group writes/reads "%s" (role %s) but the real options'
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
                    $shape->matches(1.5),
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
                    $shape->matches(1),
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
     * {@see self::discoverRuleClasses()} — a rule whose Options class lived
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
        foreach (self::ruleNameToOptionsClass() as $optionsClass) {
            foreach (self::sourceFilesByPath($optionsClass) as $sourceFile) {
                $consideredFiles[$sourceFile] = true;
            }
        }

        $escaped = [];
        $finder = (new Finder())->files()->in(self::srcDir())->name('*.php');

        foreach ($finder as $file) {
            $path = self::realOrPathname($file);

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
                . ' this guard discovered from a real rule — it cannot see whether their keys match the registry: %s',
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

        foreach (self::ruleNameToOptionsClass() as $ruleName => $optionsClass) {
            foreach (self::sourceFilesByPath($optionsClass) as $path => $sourceFile) {
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
     * arguments — never derived from the registry.
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
            $groups[] = self::extractOneCall($call);
        }

        return $groups;
    }

    /**
     * @return array{warning: list<string>, error: list<string>, threshold: list<string>}
     */
    private static function extractOneCall(Node\Expr\StaticCall $call): array
    {
        $positional = [];
        $named = [];
        foreach ($call->args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }

            if ($arg->name !== null) {
                $named[$arg->name->toString()] = $arg->value;
            } else {
                $positional[] = $arg->value;
            }
        }

        $warningKeyExpr = $named['warningKey'] ?? $positional[1] ?? null;
        $errorKeyExpr = $named['errorKey'] ?? $positional[2] ?? null;
        $thresholdKeyExpr = $named['thresholdKey'] ?? $positional[5] ?? null;
        $legacyKeysExpr = $named['legacyKeys'] ?? $positional[6] ?? null;

        $warningKey = $warningKeyExpr !== null ? self::literalString($warningKeyExpr) : null;
        $errorKey = $errorKeyExpr !== null ? self::literalString($errorKeyExpr) : null;
        $thresholdKey = ($thresholdKeyExpr !== null ? self::literalString($thresholdKeyExpr) : null) ?? RuleOptionKey::THRESHOLD;

        $legacy = $legacyKeysExpr instanceof Node\Expr\Array_
            ? self::literalLegacyKeys($legacyKeysExpr)
            : ['warning' => [], 'error' => [], 'threshold' => []];

        return [
            'warning' => $warningKey === null ? [] : [$warningKey, ...$legacy['warning']],
            'error' => $errorKey === null ? [] : [$errorKey, ...$legacy['error']],
            'threshold' => [$thresholdKey, ...$legacy['threshold']],
        ];
    }

    /**
     * @return array{warning: list<string>, error: list<string>, threshold: list<string>}
     */
    private static function literalLegacyKeys(Node\Expr\Array_ $arrayNode): array
    {
        $result = ['warning' => [], 'error' => [], 'threshold' => []];

        foreach ($arrayNode->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $role = self::literalString($item->key);
            if (!isset($result[$role])) {
                continue;
            }

            if ($item->value instanceof Node\Expr\Array_) {
                foreach ($item->value->items as $subItem) {
                    if ($subItem === null) {
                        continue;
                    }

                    $result[$role][] = self::literalString($subItem->value);
                }
            }
        }

        return $result;
    }

    /**
     * Resolves an AST node to its literal string value: a plain string
     * literal, or a `Class::CONST` fetch resolved through real reflection
     * (never a hand-typed mirror of `RuleOptionKey`'s values).
     *
     * Throws rather than returning null for anything else: a caller that
     * passed this a non-null node expects a literal back, and a role whose
     * key argument resolves to nothing would silently become an empty
     * candidate list — an assertion loop over an empty list makes zero
     * assertions and passes without having compared anything. A guard that
     * cannot resolve a call site's argument must fail loudly, not pass
     * silently.
     */
    private static function literalString(Node $node): string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if (
            $node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
        ) {
            $shortName = $node->class->getLast();
            $constName = $node->name->toString();

            // Every real call site fetches a constant from RuleOptionKey —
            // resolved through reflection against the actual class, not a
            // copy of its values.
            if ($shortName === 'RuleOptionKey' && \defined(RuleOptionKey::class . '::' . $constName)) {
                $value = (new ReflectionClassConstant(RuleOptionKey::class, $constName))->getValue();

                if (\is_string($value)) {
                    return $value;
                }
            }
        }

        throw new RuntimeException(\sprintf(
            'This guard cannot resolve a ThresholdParser::parse() call-site argument (AST node type %s) to a'
            . ' literal string — it must fail rather than silently compare against an empty candidate list.',
            $node->getType(),
        ));
    }

    /**
     * @return array<string, class-string>
     */
    private static function ruleNameToOptionsClass(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $map = [];
        foreach (self::discoverRuleClasses() as $ruleClass) {
            $ruleName = RuleNameReader::read($ruleClass);
            $map[$ruleName] = $ruleClass::getOptionsClass();
        }

        return $cache = $map;
    }

    /**
     * Scans the whole of `src/` for `*Rule.php` files and derives each one's
     * FQN mechanically from its path via the project's PSR-4 root
     * (`Qualimetrix\` => `src/`) — never from a hand-typed list of "the
     * directories rules live in", which can omit a real one silently (see
     * this class's docblock, point 4: this is what makes {@see self::readRegisteredGroups()}'s
     * completeness claim reach every rule this codebase ships, not just the
     * ones a maintainer remembered to list).
     *
     * @return list<class-string<RuleDefinitionInterface>>
     */
    private static function discoverRuleClasses(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $classes = [];
        $srcDir = self::srcDir();

        $finder = (new Finder())->files()->in($srcDir)->name('*Rule.php');

        foreach ($finder as $file) {
            $class = self::classFromSourcePath(self::realOrPathname($file));

            if (!class_exists($class) || !is_a($class, RuleInterface::class, true)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            /** @var class-string<RuleInterface> $class */
            $classes[] = $class;
        }

        return $cache = $classes;
    }

    private static function srcDir(): string
    {
        return \dirname(__DIR__, 4) . '/src';
    }

    /**
     * `SplFileInfo::getRealPath()` returns `false` only when the path cannot
     * be resolved (a dangling symlink, a file removed mid-scan) — never for
     * a real file `Finder` just found, but the return type carries the
     * possibility regardless.
     */
    private static function realOrPathname(SplFileInfo $file): string
    {
        $real = $file->getRealPath();

        return $real !== false ? $real : $file->getPathname();
    }

    /**
     * Derives a class's FQN from its absolute source path under `src/`,
     * using the project's single PSR-4 root (`Qualimetrix\` => `src/`,
     * `composer.json`'s `autoload.psr-4`) — the same mapping Composer's own
     * autoloader uses, not a re-declared copy of it.
     */
    private static function classFromSourcePath(string $absolutePath): string
    {
        $srcDir = self::srcDir();
        $relative = str_starts_with($absolutePath, $srcDir . '/')
            ? substr($absolutePath, \strlen($srcDir) + 1)
            : throw new RuntimeException(\sprintf('%s is not under %s.', $absolutePath, $srcDir));

        return 'Qualimetrix\\' . str_replace('/', '\\', substr($relative, 0, -4));
    }

    /**
     * @param class-string $optionsClass
     *
     * @return array<string, string> path => absolute source file path
     */
    private static function sourceFilesByPath(string $optionsClass): array
    {
        $paths = ['' => self::fileNameOf($optionsClass)];

        if (!is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true)) {
            return $paths;
        }

        /** @var HierarchicalRuleOptionsInterface $instance */
        $instance = new $optionsClass();

        foreach ($instance->getSupportedLevels() as $level) {
            $levelObject = $instance->forLevel($level);
            $paths[$level->value] = self::fileNameOf($levelObject::class);
        }

        return $paths;
    }

    /**
     * @param class-string $class
     */
    private static function fileNameOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();

        if ($file === false) {
            throw new RuntimeException(\sprintf('Could not locate the source file for %s.', $class));
        }

        return $file;
    }

    private static function acceptedKeysAt(string $ruleName, string $path): RuleOptionKeySet
    {
        $optionsClass = self::ruleNameToOptionsClass()[$ruleName];

        if ($path === '') {
            return $optionsClass::acceptedOptionKeys();
        }

        \assert(is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true));
        $levelClasses = $optionsClass::levelOptionsClasses();

        return $levelClasses[$path]::acceptedOptionKeys();
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

    /**
     * @return array<string, array<string, list<array{warning: list<string>, error: list<string>, threshold: list<string>, form: RuleOptionValueForm}>>>
     */
    private static function readRegisteredGroups(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $reflectionConstant = new ReflectionClassConstant(RuleThresholdKeyGroupRegistry::class, 'GROUPS');
        /** @var array<string, array<string, list<array{warning: list<string>, error: list<string>, threshold: list<string>, form: RuleOptionValueForm}>>> $value */
        $value = $reflectionConstant->getValue();

        return $cache = $value;
    }
}
