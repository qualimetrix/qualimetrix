<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleThresholdKeyGroupRegistry;
use ReflectionClass;
use ReflectionClassConstant;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Drift guard for {@see RuleThresholdKeyGroupRegistry}.
 *
 * The registry is a hand-maintained mirror of every `ThresholdParser::parse()`
 * call site's arguments (see its own docblock for why it can't be derived at
 * merge time). A hand-maintained mirror can silently rot: a new rule adds a
 * threshold group without a registry entry, an existing entry survives after
 * its rule/level is deleted, or a call site's key spelling changes without
 * the registry following. Each of those three drifts is undetectable by
 * reading the registry alone — they only show up by comparing it against the
 * real rule capability roots and the real `Options::fromArray()` behavior.
 *
 * This test derives its expectations entirely from the real code, the same
 * way {@see \Qualimetrix\Tests\Architecture\Unit\Configuration\Allow\AllowAliasExpanderTest}'s
 * reflective drift test iterates `DependencyType::cases()` instead of a
 * hand-typed list: nothing here is a second handwritten catalog of rules or
 * keys.
 *
 * - **Discovery** (which (rule, path) pairs need an entry, and how many
 *   groups): scan the explicit layered and capability-owned rule roots (the
 *   same roots their configurators register), read each rule's `NAME` via
 *   {@see RuleNameReader} and its Options class via `getOptionsClass()`
 *   (both real, no hand list), then — for hierarchical Options classes —
 *   walk `getSupportedLevels()`/`forLevel()` to find each nested Options
 *   class. For every (rule, path), the file that actually declares the
 *   `ThresholdParser::parse()` call sites is located via
 *   `ReflectionClass::getFileName()` and its own source text is searched for
 *   the literal `ThresholdParser::parse(` call — occurrence COUNT included,
 *   so a path with N calls (e.g. `code-smell.long-parameter-list`'s two
 *   pairs) must
 *   have exactly N groups declared, not just "at least one".
 * - **Existence, both directions**: every discovered (rule, path) must have
 *   a registry entry ({@see everyThresholdParserCallSiteHasAMatchingRegistryEntry});
 *   every registry entry (read via {@see ReflectionClassConstant} against
 *   the private `GROUPS` constant — no need to make it public just for
 *   testing) must correspond to a real, still-existing (rule, path)
 *   ({@see everyRegistryEntryCorrespondsToARealThresholdParserCallSite}).
 * - **Key-name accuracy**: every individual key string declared in every
 *   group (including legacy aliases) is exercised through the REAL
 *   {@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory}, config-file
 *   channel, with a differential probe — a baseline run and a run with only
 *   that key set to a sentinel must produce different results
 *   ({@see everyDeclaredKeyStillAffectsTheRealOptionsInstance}). Going
 *   through the factory (not calling `Options::fromArray()` directly)
 *   matters: a top-level rule config key is snake/kebab-case-normalized to
 *   camelCase by `RuleOptionsFactory::normalizeKeys()` before it reaches
 *   `fromArray()` (so a registry entry may name either the call site's
 *   primary spelling or one of its camelCase legacy aliases — both are
 *   real, reachable spellings), while nested dict values are NOT
 *   normalized and must match a call site's spelling exactly. If a key's
 *   real spelling changed, the probe has no effect and this fails, naming
 *   the exact rule/path/key.
 *
 * > **Known scope limit:** the occurrence-count check catches an *added*
 * > group at an already-covered path (count goes from 1 to 2, registry still
 * > has 1 → mismatch), but two groups at the same path with an IDENTICAL key
 * > set could not be told apart by count alone — no such case exists in the
 * > current rule set.
 */
#[CoversClass(RuleThresholdKeyGroupRegistry::class)]
final class RuleThresholdKeyGroupRegistryDriftTest extends TestCase
{
    private const string MARKER = 'ThresholdParser::parse(';

    private const float SENTINEL = 987654.0;

    // ------------------------------------------------------------------
    // Test 1: every (rule, path) that actually calls ThresholdParser::parse()
    // must have a registry entry, with the right number of declared groups.
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideCodeDerivedRequirements(): iterable
    {
        foreach (self::discoverThresholdRequirements() as $key => $requirement) {
            yield $key => [$requirement['ruleName'], $requirement['path'], $requirement['callCount']];
        }
    }

    #[Test]
    #[DataProvider('provideCodeDerivedRequirements')]
    public function everyThresholdParserCallSiteHasAMatchingRegistryEntry(string $ruleName, string $path, int $callCount): void
    {
        $groups = RuleThresholdKeyGroupRegistry::groupsFor($ruleName, $path);

        self::assertNotSame(
            [],
            $groups,
            \sprintf(
                'Rule "%s" (path %s) calls ThresholdParser::parse() %d time(s) but RuleThresholdKeyGroupRegistry has no'
                . ' entry for it — add one, or the merge resolver silently falls back to its unreliable suffix'
                . ' heuristic for this rule.',
                $ruleName,
                $path === '' ? '"(top level)"' : \sprintf('"%s"', $path),
                $callCount,
            ),
        );

        self::assertCount(
            $callCount,
            $groups,
            \sprintf(
                'Rule "%s" (path %s) calls ThresholdParser::parse() %d time(s) but the registry declares %d group(s)'
                . ' — they must match 1:1.',
                $ruleName,
                $path === '' ? '"(top level)"' : \sprintf('"%s"', $path),
                $callCount,
                \count($groups),
            ),
        );
    }

    // ------------------------------------------------------------------
    // Test 2: every registry entry must correspond to a real (rule, path)
    // that actually calls ThresholdParser::parse() — no stray/orphaned entries.
    // ------------------------------------------------------------------

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
    public function everyRegistryEntryCorrespondsToARealThresholdParserCallSite(string $ruleName, string $path): void
    {
        $requirements = self::discoverThresholdRequirements();

        self::assertArrayHasKey(
            $ruleName . '::' . $path,
            $requirements,
            \sprintf(
                'RuleThresholdKeyGroupRegistry declares an entry for rule "%s" (path %s), but no real Options class at'
                . ' that path calls ThresholdParser::parse() — the rule/level was removed or renamed and this entry'
                . ' is now a stray duplicate. Remove it.',
                $ruleName,
                $path === '' ? '"(top level)"' : \sprintf('"%s"', $path),
            ),
        );
    }

    // ------------------------------------------------------------------
    // Test 3: every declared key (including legacy aliases) in every group
    // must still control the value Options::fromArray() actually produces.
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideDeclaredKeys(): iterable
    {
        foreach (self::readRegisteredGroups() as $ruleName => $byPath) {
            foreach ($byPath as $path => $groupList) {
                foreach ($groupList as $groupIndex => $group) {
                    foreach (['warning', 'error', 'threshold'] as $role) {
                        foreach ($group[$role] as $key) {
                            $label = \sprintf(
                                '%s::%s#%d[%s]=%s',
                                $ruleName,
                                $path === '' ? '(top)' : $path,
                                $groupIndex,
                                $role,
                                $key,
                            );
                            yield $label => [$ruleName, $path, $key];
                        }
                    }
                }
            }
        }
    }

    #[Test]
    #[DataProvider('provideDeclaredKeys')]
    public function everyDeclaredKeyStillAffectsTheRealOptionsInstance(string $ruleName, string $path, string $key): void
    {
        $optionsClass = self::ruleNameToOptionsClass()[$ruleName] ?? null;
        self::assertNotNull($optionsClass, \sprintf('No Options class found for rule "%s" — registry is stale.', $ruleName));

        if (!is_a($optionsClass, RuleOptionsInterface::class, true)) {
            self::fail(\sprintf('%s must implement RuleOptionsInterface — registry is stale.', $optionsClass));
        }

        $isHierarchical = is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true);

        $baselineConfig = self::wrapAtPath($path, ['enabled' => true]);
        $probeConfig = self::wrapAtPath($path, ['enabled' => true, $key => self::SENTINEL]);

        // Goes through the real RuleOptionsFactory (config-file channel), not
        // Options::fromArray() directly — see this class's docblock for why
        // that matters for top-level (non-normalized-elsewhere) keys.
        $baselineRegistry = new RuleOptionsRegistry();
        $baselineRegistry->setConfigFileOptions([$ruleName => $baselineConfig]);
        $baseline = (new RuleOptionsFactory($baselineRegistry))->create($ruleName, $optionsClass);

        $probeRegistry = new RuleOptionsRegistry();
        $probeRegistry->setConfigFileOptions([$ruleName => $probeConfig]);
        $probe = (new RuleOptionsFactory($probeRegistry))->create($ruleName, $optionsClass);

        $baselineTargets = self::inspectionTargets($baseline, $path, $isHierarchical);
        $probeTargets = self::inspectionTargets($probe, $path, $isHierarchical);

        $differs = false;
        foreach ($baselineTargets as $i => $baselineTarget) {
            if (get_object_vars($baselineTarget) !== get_object_vars($probeTargets[$i])) {
                $differs = true;
                break;
            }
        }

        self::assertTrue(
            $differs,
            \sprintf(
                'RuleThresholdKeyGroupRegistry declares key "%s" for rule "%s" (path %s), but setting it had NO'
                . ' observable effect on %s::fromArray() — the real key name has drifted; update the registry to match.',
                $key,
                $ruleName,
                $path === '' ? '"(top level)"' : \sprintf('"%s"', $path),
                $optionsClass,
            ),
        );
    }

    // ------------------------------------------------------------------
    // Shared discovery helpers — all reflection/source-text based, no hand lists.
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{ruleName: string, path: string, callCount: int}>
     */
    private static function discoverThresholdRequirements(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $requirements = [];

        foreach (self::ruleNameToOptionsClass() as $ruleName => $optionsClass) {
            foreach (self::sourceFilesByPath($optionsClass) as $path => $sourceFile) {
                $source = file_get_contents($sourceFile);
                if ($source === false) {
                    throw new RuntimeException(\sprintf('Could not read %s.', $sourceFile));
                }

                $callCount = substr_count(self::stripComments($source), self::MARKER);
                if ($callCount === 0) {
                    continue;
                }

                $requirements[$ruleName . '::' . $path] = [
                    'ruleName' => $ruleName,
                    'path' => $path,
                    'callCount' => $callCount,
                ];
            }
        }

        return $cache = $requirements;
    }

    /**
     * Removes comment/docblock tokens before counting `ThresholdParser::parse(`
     * occurrences — a docblock merely MENTIONING the call (as several of
     * these Options classes do, e.g. `LongParameterListOptions`'s class
     * docblock) must not inflate the count against real call sites.
     */
    private static function stripComments(string $source): string
    {
        $codeOnly = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                    continue;
                }

                $codeOnly .= $token[1];
            } else {
                $codeOnly .= $token;
            }
        }

        return $codeOnly;
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
     * Scans the complete explicit union of capability-owned rule roots, so extraction cannot silently
     * remove a threshold rule from this drift guard.
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
        $srcDir = \dirname(__DIR__, 4) . '/src';
        $roots = [
            [$srcDir . '/Analysis/Evidence/Duplication', 'Qualimetrix\\Analysis\\Evidence\\Duplication\\'],
            [$srcDir . '/Analysis/Evidence/CodeSmell', 'Qualimetrix\\Analysis\\Evidence\\CodeSmell\\'],
            [$srcDir . '/Analysis/Evidence/Cohesion', 'Qualimetrix\\Analysis\\Evidence\\Cohesion\\'],
            [$srcDir . '/Analysis/Evidence/Complexity', 'Qualimetrix\\Analysis\\Evidence\\Complexity\\'],
            [$srcDir . '/Analysis/Evidence/Coupling', 'Qualimetrix\\Analysis\\Evidence\\Coupling\\'],
            [$srcDir . '/Analysis/Evidence/Design', 'Qualimetrix\\Analysis\\Evidence\\Design\\'],
            [$srcDir . '/Analysis/Evidence/Maintainability', 'Qualimetrix\\Analysis\\Evidence\\Maintainability\\'],
            [$srcDir . '/Analysis/Evidence/Security', 'Qualimetrix\\Analysis\\Evidence\\Security\\'],
            [$srcDir . '/Analysis/Evidence/Size', 'Qualimetrix\\Analysis\\Evidence\\Size\\'],
        ];

        foreach ($roots as [$rulesDir, $namespace]) {
            $finder = (new Finder())->files()->in($rulesDir)->name('*Rule.php')->notName('AbstractRule.php');

            foreach ($finder as $file) {
                $class = $namespace . str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));

                if (!class_exists($class) || !is_a($class, RuleInterface::class, true)) {
                    continue;
                }

                // Matches the service-registration Abstract*.php exclusion,
                // generalized via reflection so nested abstract bases are
                // skipped without a second name catalog.
                if ((new ReflectionClass($class))->isAbstract()) {
                    continue;
                }

                /** @var class-string<RuleInterface> $class */
                $classes[] = $class;
            }
        }

        return $cache = $classes;
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

    /**
     * @return array<string, array<string, list<array{warning: list<string>, error: list<string>, threshold: list<string>}>>>
     */
    private static function readRegisteredGroups(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $reflectionConstant = new ReflectionClassConstant(RuleThresholdKeyGroupRegistry::class, 'GROUPS');
        /** @var array<string, array<string, list<array{warning: list<string>, error: list<string>, threshold: list<string>}>>> $value */
        $value = $reflectionConstant->getValue();

        return $cache = $value;
    }

    /**
     * @param array<string, mixed> $flat
     *
     * @return array<string, mixed>
     */
    private static function wrapAtPath(string $path, array $flat): array
    {
        if ($path === '') {
            return $flat;
        }

        $result = $flat;
        foreach (array_reverse(explode('.', $path)) as $segment) {
            $result = [$segment => $result];
        }

        return $result;
    }

    /**
     * Resolves the object(s) whose public properties should be compared for
     * a given nesting path. For a flat rule, that's the instance itself. For
     * a hierarchical rule at a specific nested level, that's the
     * `forLevel()` result for the matching level. For a hierarchical rule's
     * top level (`''`, the "legacy flat" branch), the parsed values could
     * land on any one of its supported levels — every rule with such a
     * branch currently routes it to the `method` level, but rather than
     * hard-coding that, all supported levels are compared and a difference
     * on ANY of them counts.
     *
     * @return list<object>
     */
    private static function inspectionTargets(object $instance, string $path, bool $isHierarchical): array
    {
        if (!$isHierarchical) {
            return [$instance];
        }

        \assert($instance instanceof HierarchicalRuleOptionsInterface);

        if ($path === '') {
            $targets = [];
            foreach ($instance->getSupportedLevels() as $level) {
                $targets[] = $instance->forLevel($level);
            }

            return $targets;
        }

        foreach ($instance->getSupportedLevels() as $level) {
            if ($level->value === $path) {
                return [$instance->forLevel($level)];
            }
        }

        return [$instance];
    }
}
