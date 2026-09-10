<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\IdenticalSubExpressionRule;
use Qualimetrix\Analysis\Evidence\Coupling\UnmatchedFrameworkNamespaceRule;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule;
use Qualimetrix\Analysis\Evidence\Security\HardcodedCredentialsRule;
use Qualimetrix\Analysis\Evidence\Security\SensitiveParameterRule;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationFinding;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnmatchedExcludeDiagnostic;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/**
 * X10 (`01-freeze-kind.md`) froze `OccurrenceKey`'s discriminator away from
 * the channel code in six families, and X16 added four more: each carries a
 * private `OCCURRENCE_KIND` constant, pinned as a **literal** and **not**
 * reading `NAME`/`code`, so that a future rename of the channel does not move
 * `occurrence` for every already-accepted baseline entry on it. The first six
 * pinned the channel spelling as it stood at freeze time; the four added later
 * pin a description of what one finding is about, because one of them serves
 * three channels at once and no single channel code could name it. What is
 * frozen is the literal, not which words it uses. `NAME` moving
 * out from under a pinned `OCCURRENCE_KIND` — as the X12 rename already did
 * for `duplication.code-duplication` — is the freeze working as designed, not
 * a drift to correct.
 *
 * Nothing that runs on every `composer test` re-proves the freeze holds after
 * today. The per-family pin tests (`itKeysOccurrenceToTheFrozenChannelSpelling*`)
 * each exercise one family's own production wiring; none of them would catch
 * every family regressing to `self::NAME` at once, and none would notice a
 * seventh family added without its own freeze. This guard re-derives the
 * frozen set from source text on every run — the same
 * `OCCURRENCE_KIND = '<literal>'` declaration shape
 * `scripts/generate-rename-enumeration.php` scans for when it flags these
 * occurrences as protected — rather than trusting a hand-kept class list, so
 * a class silently losing its literal form (rewritten as `self::NAME`, or as
 * any other expression) drops out of the measured set instead of quietly
 * passing.
 *
 * The declaration alone is not the freeze: a constant nobody reads is dead
 * code, and `OccurrenceKey::semantic()`'s first argument is where the
 * discriminator actually lands. This guard therefore also reads, per
 * declaring file, every `OccurrenceKey::semantic(` call and requires the
 * first argument to spell `self::OCCURRENCE_KIND` — so swapping the call
 * site back to `self::NAME` (or any other expression) while leaving the
 * declaration untouched fails here, instead of leaving a frozen constant
 * that nothing in production consults.
 */
final class OccurrenceKindFreezeGuardTest extends TestCase
{
    private const int EXPECTED_FROZEN_COUNT = 10;

    /**
     * The frozen spelling itself, pinned by literal rather than derived from
     * any rule's current `NAME`. A channel rename on the future rename step
     * changes `NAME` and must NOT change these values — that divergence is
     * the freeze working as designed, not a defect to chase. Comparing
     * against `NAME` instead of a pin was the trap this guard used to set:
     * a rename would turn the comparison red with a message reading like
     * "the constant drifted, bring it back in line", and doing that would
     * silently retire the freeze via the exact channel the freeze exists to
     * survive — moving `occurrence` under every baseline entry, GitLab
     * fingerprint and SARIF `partialFingerprints` value already accepted
     * against these six findings. Changing a value here is a breaking change
     * to all three; it needs its own CHANGELOG entry, not a quiet update.
     *
     * @var array<class-string, string>
     */
    private const array FROZEN_SPELLING = [
        HardcodedCredentialsRule::class => 'security.hardcoded-credentials',
        SensitiveParameterRule::class => 'security.sensitive-parameter',
        CircularDependencyRule::class => 'architecture.circular-dependency',
        IdenticalSubExpressionRule::class => 'code-smell.identical-subexpression',
        CodeDuplicationRule::class => 'duplication.code-duplication',
        LayerViolationFinding::class => 'architecture.layer-violation',
        // X16 froze four more, and these four do not spell a channel code.
        // Three of them belong to producers whose finding carries the value
        // that makes it distinct — an exclude pattern, a suppression value, a
        // framework prefix — and one of those producers publishes three
        // channels through one constructor, so no single channel spelling
        // could name it. The pin is a literal either way, which is the whole
        // requirement: it must not follow a rename of the channel.
        UnmatchedExcludeAudit::class => 'unmatched-exclude-pattern',
        UnboundSuppressionAudit::class => 'unbound-suppression-value',
        UnmatchedFrameworkNamespaceRule::class => 'unmatched-framework-prefix',
        UnmatchedExcludeDiagnostic::class => 'inert-layer-exclude-clause',
    ];

    #[Test]
    public function itKeepsEveryFrozenOccurrenceKindAsAPlainLiteralMatchingItsPin(): void
    {
        $root = self::projectRoot();
        $declarations = self::findFrozenDeclarations($root);

        self::assertCount(
            self::EXPECTED_FROZEN_COUNT,
            $declarations,
            \sprintf(
                "Expected exactly %d declarations shaped `private const string OCCURRENCE_KIND = '<literal>';` under"
                . ' src/ — the families 01-freeze-kind.md names. Found %d: %s. A class that rewrites the'
                . ' constant as an expression (e.g. `self::NAME`) drops out of this count instead of failing loudly'
                . ' elsewhere, which is exactly what this assertion exists to catch. If a new family was'
                . ' deliberately frozen, update this expectation and 01-freeze-kind.md\'s table together.',
                self::EXPECTED_FROZEN_COUNT,
                \count($declarations),
                $declarations === [] ? '(none)' : implode(', ', array_column($declarations, 'file')),
            ),
        );

        $mismatches = [];

        foreach ($declarations as $declaration) {
            $class = self::classFromPath($declaration['file'], $root);

            if (!class_exists($class)) {
                $mismatches[] = \sprintf(
                    '%s: declaration text was found but "%s" is not a loadable class — check the'
                    . ' path-to-namespace mapping.',
                    $declaration['file'],
                    $class,
                );

                continue;
            }

            $reflection = new ReflectionClass($class);

            if (!$reflection->hasConstant('OCCURRENCE_KIND')) {
                $mismatches[] = \sprintf('%s: source text declares OCCURRENCE_KIND but the class has none at runtime.', $class);

                continue;
            }

            $runtimeValue = $reflection->getConstant('OCCURRENCE_KIND');

            if ($runtimeValue !== $declaration['literal']) {
                $mismatches[] = \sprintf(
                    '%s::OCCURRENCE_KIND evaluates to "%s" but its source text is the literal "%s" — the constant'
                    . ' is no longer a plain string literal.',
                    $class,
                    (string) $runtimeValue,
                    $declaration['literal'],
                );

                continue;
            }

            $expectedSpelling = self::FROZEN_SPELLING[$class] ?? null;

            if ($expectedSpelling === null) {
                $mismatches[] = \sprintf(
                    '%s: declares OCCURRENCE_KIND but is not in FROZEN_SPELLING — a family was added, removed or'
                    . ' renamed without updating the pin. If this is a deliberate new freeze, add it to'
                    . ' FROZEN_SPELLING with its current spelling (not derived from NAME) and record it in'
                    . ' 01-freeze-kind.md\'s table.',
                    $class,
                );

                continue;
            }

            if ($runtimeValue !== $expectedSpelling) {
                $mismatches[] = \sprintf(
                    '%s::OCCURRENCE_KIND is now "%s" but the frozen pin says "%s". This is a breaking change: it'
                    . ' moves `occurrence` under every already-accepted baseline entry, GitLab fingerprint and SARIF'
                    . ' partialFingerprints value for this finding. Do NOT "fix" this by re-pinning to match the'
                    . ' current value without checking whether the channel it names was renamed on purpose — a'
                    . ' channel rename is expected to leave this pin untouched, not follow it.',
                    $class,
                    $runtimeValue,
                    $expectedSpelling,
                );
            }

            $callSiteMismatch = self::findCallSiteMismatch($declaration['file'], $class);

            if ($callSiteMismatch !== null) {
                $mismatches[] = $callSiteMismatch;
            }
        }

        self::assertSame([], $mismatches, "\n" . implode("\n", $mismatches));
    }

    /**
     * The declaration proves the constant exists; this proves it is used.
     * Every `OccurrenceKey::semantic(` call in the declaring file must pass
     * `self::OCCURRENCE_KIND` as its first argument — a call site rewritten
     * to `self::NAME` (or anything else) leaves the declaration in place
     * while the discriminator it froze quietly starts following the channel
     * code again, which the declaration-only checks above cannot see.
     */
    private static function findCallSiteMismatch(string $file, string $class): ?string
    {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $file));
        }

        preg_match_all('/OccurrenceKey::semantic\(\s*([^,]+?)\s*,/', $source, $matches);
        $firstArguments = $matches[1];

        if ($firstArguments === []) {
            return \sprintf(
                '%s: declares OCCURRENCE_KIND but calls no OccurrenceKey::semantic() in the same file — the'
                . ' constant is unused.',
                $class,
            );
        }

        $wrongArguments = array_values(array_unique(array_filter(
            $firstArguments,
            static fn(string $argument): bool => $argument !== 'self::OCCURRENCE_KIND',
        )));

        if ($wrongArguments !== []) {
            return \sprintf(
                '%s: OccurrenceKey::semantic() is called with "%s" as its first argument instead of'
                . ' self::OCCURRENCE_KIND — the frozen constant is declared but no longer used to key the'
                . ' occurrence, so it is dead code and the discriminator has silently gone back to following'
                . ' whatever that expression evaluates to.',
                $class,
                implode('", "', $wrongArguments),
            );
        }

        return null;
    }

    /**
     * @return list<array{file: string, literal: string}>
     */
    private static function findFrozenDeclarations(string $root): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );

        $found = [];

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'php') {
                continue;
            }

            $path = $fileInfo->getPathname();
            $source = file_get_contents($path);

            if ($source === false) {
                throw new RuntimeException(\sprintf('Could not read %s.', $path));
            }

            if (preg_match("/private const string OCCURRENCE_KIND = '((?:[^'\\\\]|\\\\.)*)';/", $source, $match) === 1) {
                $found[] = ['file' => $path, 'literal' => stripcslashes($match[1])];
            }
        }

        usort($found, static fn(array $a, array $b): int => $a['file'] <=> $b['file']);

        return $found;
    }

    private static function classFromPath(string $absolutePath, string $root): string
    {
        $relative = substr($absolutePath, \strlen($root . '/src/'));
        $withoutExtension = substr($relative, 0, -\strlen('.php'));

        return 'Qualimetrix\\' . str_replace('/', '\\', $withoutExtension);
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
