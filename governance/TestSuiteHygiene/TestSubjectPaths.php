<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use JsonException;
use LogicException;

/**
 * What a test file's path claims about the subject that owns it.
 *
 * The claim is three parts, and the third is the one a path-only reading
 * cannot make:
 *
 * 1. **Owner** — the segments before the level segment name one of the
 *    manifest's owners. `Core.Neutral` is spelled `tests/Core`, the one owner
 *    whose name is not a namespace, written here rather than implied.
 * 2. **Level** — exactly one segment is `Unit`, `Integration` or `Functional`,
 *    and it is the segment immediately after the owner. A level that is not
 *    immediately below the owner needs no check of its own: it makes the
 *    segments before it a longer path that is not one of the owners.
 * 3. **Remainder is a prefix** — the path below the level is a segment-wise
 *    prefix of the covered class's namespace remainder, for at least one
 *    `#[CoversClass]` whose owner equals the path owner.
 *
 * **Part 3 is a prefix test and not an equality**, because filing a test flat
 * under `{owner}/{level}/` while its subject sits in a sub-namespace is this
 * tree's convention — measured at 132 files. The prefix form still refuses what
 * an equality form was introduced for: `Reporting/Unit/Formatter/Whatever/`
 * covering `Reporting\Formatter\Html\X` gives remainder `Formatter/Whatever`
 * against an actual `Formatter/Html`, which is not a prefix of it.
 *
 * **The asymmetry, stated rather than left for the next reader: this validates
 * paths against owners, never owners against paths.** `Core.Profiler` is a
 * manifest owner with no `tests/Core/Profiler` directory and no test class
 * anywhere beneath it, and that is not a failure here — it is a question this
 * does not ask.
 *
 * **Population is `tests/**\/*Test.php`, stated as a pattern.** Support and
 * fixture files have no level segment and are excluded deliberately, not by
 * omission: a control that judged them would refuse
 * `tests/Reporting/Support/StubChannelPresentation.php` for being what it is.
 *
 * The three verdicts that are neither exact nor prefix are the ones
 * {@see SubjectPathExceptions} carries under a ceiling. They are not degrees of
 * the same failure: a file with no coverage attribute makes no claim to check,
 * a file covering another owner's classes makes a claim about a subject it is
 * not filed under, and a file whose remainder is not a prefix makes the claim
 * this part exists to refuse.
 */
final class TestSubjectPaths
{
    public const MANIFEST = 'docs/internal/modular-architecture-manifest.json';

    /** The population's root. Everything judged here lies under it. */
    public const ROOT = 'tests';

    /**
     * The one owner whose name is not its namespace.
     */
    public const NEUTRAL_OWNER = 'Core.Neutral';

    public const NEUTRAL_OWNER_PATH = 'Core';

    /** @var list<string> */
    public const LEVELS = ['Unit', 'Integration', 'Functional'];

    /** The remainder is exactly the covered class's namespace remainder. */
    public const EXACT = 'exact';

    /** The remainder is a proper prefix of it — filed flatter than the subject sits. */
    public const PREFIX = 'prefix';

    /** Part 1: the segments before the level name no manifest owner. */
    public const UNKNOWN_OWNER = 'unknown-owner';

    /** Part 2: the path does not carry exactly one level segment. */
    public const LEVEL = 'level';

    /** List A: the file declares no `#[CoversClass]` at all. */
    public const NO_COVERAGE = 'no-coverage';

    /** List B: every class the file covers is owned by someone else. */
    public const ANOTHER_OWNER = 'another-owner';

    /** List C: the path owner is among the covered owners, and the remainder is not a prefix. */
    public const NOT_A_PREFIX = 'not-a-prefix';

    /** @var array<string, string>|null owner path => manifest owner name */
    private static ?array $ownerPaths = null;

    /** @var array<string, string>|null declared class => its owner's path */
    private static ?array $declarationOwners = null;

    /**
     * The manifest's owners, keyed by the path a test file spells them with.
     *
     * @return array<string, string> owner path => manifest owner name
     */
    public static function ownerPaths(): array
    {
        if (self::$ownerPaths !== null) {
            return self::$ownerPaths;
        }

        $paths = [];
        foreach (self::manifest()['owners'] as $owner) {
            if (!\is_string($owner)) {
                throw new LogicException(self::MANIFEST . ' names an owner that is not a string');
            }

            $paths[self::pathOf($owner)] = $owner;
        }

        return self::$ownerPaths = $paths;
    }

    /**
     * Every production declaration, mapped to the path its owner is spelled with.
     *
     * @return array<string, string> fully qualified class name => owner path
     */
    public static function declarationOwners(): array
    {
        if (self::$declarationOwners !== null) {
            return self::$declarationOwners;
        }

        $owners = [];
        foreach (self::manifest()['declarations'] as $class => $declaration) {
            if (!\is_string($class) || !\is_array($declaration) || !\is_string($declaration['owner'] ?? null)) {
                throw new LogicException(self::MANIFEST . ' carries a declaration without a string owner');
            }

            $owners[$class] = self::pathOf($declaration['owner']);
        }

        return self::$declarationOwners = $owners;
    }

    /**
     * Every `tests/**\/*Test.php`, which is the whole population and nothing else.
     *
     * @return list<string> project-relative paths
     */
    public static function population(): array
    {
        return TestTree::testFilesIn(self::ROOT);
    }

    /**
     * The whole population judged, path => verdict and the detail that names why.
     *
     * @return array<string, array{verdict: string, detail: string}>
     */
    public static function measure(): array
    {
        $ownerPaths = self::ownerPaths();
        $declarationOwners = self::declarationOwners();

        $judged = [];
        foreach (self::population() as $path) {
            $covers = TestTree::declarationsIn($path)['covers'];
            $judged[$path] = self::judge($path, $covers['classes'], $covers['nothing'], $ownerPaths, $declarationOwners);
        }

        ksort($judged);

        return $judged;
    }

    /**
     * The paths carrying one verdict, with their details.
     *
     * @param array<string, array{verdict: string, detail: string}> $judged
     *
     * @return array<string, string> path => detail
     */
    public static function carrying(array $judged, string $verdict): array
    {
        $carrying = [];
        foreach ($judged as $path => $answer) {
            if ($answer['verdict'] === $verdict) {
                $carrying[$path] = $answer['detail'];
            }
        }

        return $carrying;
    }

    /**
     * One file's verdict, from handed-in facts alone.
     *
     * Handed in rather than read, so the rule can be refused on probes without
     * a probe ever reaching the corpus the real scan is judged against.
     *
     * @param list<string> $covered fully qualified names the file claims to cover
     * @param array<string, string> $ownerPaths owner path => manifest owner name
     * @param array<string, string> $declarationOwners class => owner path
     *
     * @return array{verdict: string, detail: string}
     */
    public static function judge(
        string $path,
        array $covered,
        bool $coversNothing,
        array $ownerPaths,
        array $declarationOwners,
    ): array {
        if (!str_starts_with($path, self::ROOT . '/')) {
            throw new LogicException($path . ' lies outside ' . self::ROOT . '/');
        }

        $segments = explode('/', substr($path, \strlen(self::ROOT) + 1));
        $directories = \array_slice($segments, 0, -1);

        $levels = array_keys(array_filter(
            $directories,
            static fn(string $segment): bool => \in_array($segment, self::LEVELS, true),
        ));

        if (\count($levels) !== 1) {
            return ['verdict' => self::LEVEL, 'detail' => \sprintf(
                'names %d of %s, and a test file names exactly one',
                \count($levels),
                implode(', ', self::LEVELS),
            )];
        }

        $level = $levels[0];
        $owner = implode('/', \array_slice($directories, 0, $level));
        if (!isset($ownerPaths[$owner])) {
            return ['verdict' => self::UNKNOWN_OWNER, 'detail' => \sprintf(
                '%s is before its %s segment, and no manifest owner is spelled that way',
                $owner === '' ? '(nothing)' : $owner,
                $directories[$level],
            )];
        }

        $remainder = \array_slice($directories, $level + 1);
        if ($covered === []) {
            return ['verdict' => self::NO_COVERAGE, 'detail' => $coversNothing
                ? 'declares #[CoversNothing]'
                : 'declares no coverage attribute'];
        }

        $coveredOwners = [];
        $subjects = [];
        $prefix = false;
        foreach ($covered as $class) {
            $coveredOwner = $declarationOwners[$class] ?? null;
            if ($coveredOwner === null) {
                continue;
            }

            $coveredOwners[$coveredOwner] = true;
            if ($coveredOwner !== $owner) {
                continue;
            }

            $subject = self::subjectRemainder($class, $owner);
            $subjects[] = self::spell($subject);
            if ($remainder === $subject) {
                return ['verdict' => self::EXACT, 'detail' => self::spell($remainder)];
            }

            $prefix = $prefix || $remainder === \array_slice($subject, 0, \count($remainder));
        }

        if ($prefix) {
            return ['verdict' => self::PREFIX, 'detail' => self::spell($remainder)];
        }

        if ($coveredOwners !== [] && !isset($coveredOwners[$owner])) {
            $names = array_map(
                static fn(string $path): string => $ownerPaths[$path] ?? $path,
                array_keys($coveredOwners),
            );
            sort($names);

            return ['verdict' => self::ANOTHER_OWNER, 'detail' => \sprintf(
                'is filed under %s and covers only %s',
                $ownerPaths[$owner],
                implode(' + ', $names),
            )];
        }

        return ['verdict' => self::NOT_A_PREFIX, 'detail' => \sprintf(
            '%s against %s',
            self::spell($remainder),
            $subjects === [] ? '(nothing the manifest declares)' : implode(' / ', array_unique($subjects)),
        )];
    }

    /**
     * The namespace a covered class sits in, below its owner.
     *
     * The owner's path is a segment-wise prefix of every class it owns —
     * measured over the whole manifest, not assumed — so this cannot silently
     * slice a name in half.
     *
     * @return list<string>
     */
    public static function subjectRemainder(string $class, string $ownerPath): array
    {
        $segments = explode('\\', $class);
        if (($segments[0] ?? '') === 'Qualimetrix') {
            array_shift($segments);
        }

        array_pop($segments);
        $owner = explode('/', $ownerPath);
        if (\array_slice($segments, 0, \count($owner)) !== $owner) {
            throw new LogicException($class . ' is owned by ' . $ownerPath . ', which is not where its name puts it');
        }

        return \array_slice($segments, \count($owner));
    }

    /** The path a manifest owner is spelled with under `tests/`. */
    public static function pathOf(string $owner): string
    {
        return $owner === self::NEUTRAL_OWNER ? self::NEUTRAL_OWNER_PATH : str_replace('.', '/', $owner);
    }

    /** @param list<string> $segments */
    private static function spell(array $segments): string
    {
        return $segments === [] ? '(the level itself)' : implode('/', $segments);
    }

    /** @return array{owners: list<mixed>, declarations: array<mixed, mixed>} */
    private static function manifest(): array
    {
        $contents = file_get_contents(TestTree::absolute(self::MANIFEST));
        if ($contents === false) {
            throw new LogicException(self::MANIFEST . ' is not readable');
        }

        try {
            $manifest = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException(self::MANIFEST . ' is not readable JSON', 0, $exception);
        }

        if (!\is_array($manifest) || !\is_array($manifest['owners'] ?? null) || !\is_array($manifest['declarations'] ?? null)) {
            throw new LogicException(self::MANIFEST . ' carries no owners and declarations');
        }

        return ['owners' => array_values($manifest['owners']), 'declarations' => $manifest['declarations']];
    }
}
