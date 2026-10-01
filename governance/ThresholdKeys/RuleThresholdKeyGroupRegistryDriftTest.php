<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ThresholdKeys;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use RuntimeException;

/**
 * Compares declared band populations with real parser call counts, and tests
 * every accepted key spelling through the document engine and options builder.
 *
 * Counts and literal membership cannot distinguish two references to one
 * band from references to two bands. Differential probes separately observe
 * the real effect of each declared key instead of trusting metadata alone.
 */
#[CoversClass(RuleOptionSurface::class)]
final class RuleThresholdKeyGroupRegistryDriftTest extends TestCase
{
    private const string MARKER = 'ThresholdParser::parse(';

    private const int SENTINEL = 987654;

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
    public function itGivesEveryThresholdParserCallSiteAMatchingRegistryEntry(string $ruleName, string $path, int $callCount): void
    {
        $groups = ThresholdRuleDiscovery::registeredGroups()[$ruleName][$path] ?? [];

        self::assertNotSame(
            [],
            $groups,
            \sprintf(
                'Rule "%s" (path %s) calls ThresholdParser::parse() %d time(s) but RuleOptionSurface has no'
                . ' entry for it — the read must use a band declared by its owning options class.',
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
        foreach (ThresholdRuleDiscovery::registeredGroups() as $ruleName => $byPath) {
            foreach (array_keys($byPath) as $path) {
                yield $ruleName . '::' . $path => [$ruleName, $path];
            }
        }
    }

    #[Test]
    #[DataProvider('provideRegistryEntries')]
    public function itMakesEveryRegistryEntryCorrespondToARealThresholdParserCallSite(string $ruleName, string $path): void
    {
        $requirements = self::discoverThresholdRequirements();

        self::assertArrayHasKey(
            $ruleName . '::' . $path,
            $requirements,
            \sprintf(
                'RuleOptionSurface declares an entry for rule "%s" (path %s), but no real Options class at'
                . ' that path calls ThresholdParser::parse() — the rule/level was removed or renamed and this entry'
                . ' is now a stray duplicate. Remove it.',
                $ruleName,
                $path === '' ? '"(top level)"' : \sprintf('"%s"', $path),
            ),
        );
    }

    // ------------------------------------------------------------------
    // Test 3: every accepted spelling of every declared key in each band
    // must still control the value Options::fromResolved() actually produces.
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideDeclaredKeys(): iterable
    {
        foreach (ThresholdRuleDiscovery::registeredGroups() as $ruleName => $byPath) {
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
    public function itKeepsEveryDeclaredKeyAffectingTheRealOptionsInstance(string $ruleName, string $path, string $key): void
    {
        $optionsClass = ThresholdRuleDiscovery::ruleNameToOptionsClass()[$ruleName] ?? null;
        self::assertNotNull($optionsClass, \sprintf('No Options class found for rule "%s" — registry is stale.', $ruleName));

        if (!is_a($optionsClass, RuleOptionsInterface::class, true)) {
            self::fail(\sprintf('%s must implement RuleOptionsInterface — registry is stale.', $optionsClass));
        }

        $isHierarchical = is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true);

        $surface = RuleOptionSurface::of($optionsClass);
        $keySet = $path === '' ? $surface->ownKeySet() : $surface->keySetAtLevel($path);
        self::assertNotNull($keySet, 'A discovered band must have an owning declaration.');
        $normalized = ConfigKeySpelling::normalize($key);
        $companion = [];
        $found = false;
        foreach ($keySet->bands() as $band) {
            if ($normalized === ConfigKeySpelling::normalize($band->shorthand)) {
                $found = true;
                break;
            }
            if ($normalized === ConfigKeySpelling::normalize($band->warning)) {
                $companion[$band->error] = $band->direction === BandDirection::Rising ? self::SENTINEL + 1 : 0;
                $found = true;
                break;
            }
            if ($normalized === ConfigKeySpelling::normalize($band->error)) {
                $companion[$band->warning] = $band->direction === BandDirection::Rising ? 0 : self::SENTINEL + 1;
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'A discovered spelling must address its declared band.');
        $baselineConfig = self::wrapAtPath($path, ['enabled' => true, ...$companion]);
        $probeConfig = self::wrapAtPath($path, ['enabled' => true, ...$companion, $key => self::SENTINEL]);

        // The document engine must expand shorthands before the real builder reads them.
        $execution = self::createStub(RuleExecutionInterface::class);
        $execution->method('allRules')->willReturn([new RuleMetadata($ruleName, $optionsClass, '', [], false)]);
        $baseline = ResolvedOptionsFixture::build(ResolvedOptionsFixture::authoredConfiguration(['rules' => [$ruleName => $baselineConfig]], $execution->allRules()), $execution->allRules())->for($ruleName);
        $probe = ResolvedOptionsFixture::build(ResolvedOptionsFixture::authoredConfiguration(['rules' => [$ruleName => $probeConfig]], $execution->allRules()), $execution->allRules())->for($ruleName);

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
                'RuleOptionSurface declares key "%s" for rule "%s" (path %s), but setting it had NO'
                . ' observable effect on %s::fromResolved() — the real key name has drifted; update the registry to match.',
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

        foreach (ThresholdRuleDiscovery::ruleNameToOptionsClass() as $ruleName => $optionsClass) {
            foreach (ThresholdRuleDiscovery::sourceFilesByPath($optionsClass) as $path => $sourceFile) {
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
