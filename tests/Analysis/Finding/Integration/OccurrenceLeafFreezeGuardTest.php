<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\BooleanArgumentRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\CountInLoopRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\DebugCodeRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\EmptyCatchRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ErrorSuppressionRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\EvalRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ExitRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\SuperglobalsRule;
use Qualimetrix\Analysis\Evidence\Security\CommandInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\SqlInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\XssRule;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/**
 * The sibling {@see OccurrenceKindFreezeGuardTest} guards the six families
 * whose `OccurrenceKey` discriminator is a private `OCCURRENCE_KIND` constant
 * spelling the channel code. Twelve further families key `occurrence` a
 * different way: `AbstractCodeSmellRule` and `AbstractSecurityPatternRule`
 * pass a `SMELL_TYPE`/`PATTERN_TYPE` constant through their finding VO into
 * `OccurrenceKey::semantic()`, and that constant holds the channel's LEAF in
 * snake_case (`'eval'`, `'sql_injection'`) rather than its code.
 *
 * Nothing protected those twelve. Renaming one family's leaf consistently
 * across its declaration, the collector's type list, the visitor's producing
 * literal and every test literal including the `codeSmell.<leaf>` bag key
 * left the whole suite green while the discriminator moved — i.e. moved
 * `occurrence` under every already-accepted baseline entry, GitLab
 * fingerprint and SARIF `partialFingerprints` value on the channel. A
 * PARTIAL sweep of the same rename is red; only the complete,
 * self-consistent one was invisible, and the complete one is what the final
 * rename step performs.
 *
 * This guard is a separate class rather than an extension of the sibling
 * because almost nothing transfers: a different declaration shape, a
 * different constant-to-channel relationship (leaf, not code), a different
 * expected count, and no `self::OCCURRENCE_KIND` call-site rule to check —
 * these families never name the constant at the call site at all. Folding
 * both into one class would leave every failure message hedging about which
 * of two mechanisms broke.
 *
 * Like its sibling it derives the measured set from source text on every run
 * — the same shapes `scripts/generate-rename-enumeration.php` scans for when
 * it fills `leaf_const`/`leaf_pin` — so a family that stops declaring the
 * constant drops out of the count instead of quietly passing, and a
 * thirteenth family adopting the shape fails here until it is pinned.
 */
final class OccurrenceLeafFreezeGuardTest extends TestCase
{
    private const int EXPECTED_LEAF_COUNT = 12;

    /**
     * The frozen leaf itself, pinned by literal rather than derived from any
     * rule's current `NAME`. The leaf is NOT the channel code and is not
     * mechanically derivable from it: `code-smell.count-in-loop` keys on
     * `'count_in_loop'`, `security.sql-injection` on `'sql_injection'`.
     *
     * A channel rename on the final rename step changes `NAME` and must NOT
     * change these values, and must not "restore consistency" by respelling
     * the constant to match the new code — that divergence is the freeze
     * working as designed. Doing it anyway moves `occurrence` under every
     * accepted baseline entry, GitLab fingerprint and SARIF
     * `partialFingerprints` value for the channel, silently, through the
     * exact door this guard exists to hold shut. Changing a value here is a
     * breaking change needing its own CHANGELOG entry, not a quiet update.
     *
     * @var array<class-string, string>
     */
    private const array FROZEN_LEAF = [
        BooleanArgumentRule::class => 'boolean_argument',
        CountInLoopRule::class => 'count_in_loop',
        DebugCodeRule::class => 'debug_code',
        EmptyCatchRule::class => 'empty_catch',
        ErrorSuppressionRule::class => 'error_suppression',
        EvalRule::class => 'eval',
        ExitRule::class => 'exit',
        GotoRule::class => 'goto',
        SuperglobalsRule::class => 'superglobals',
        CommandInjectionRule::class => 'command_injection',
        SqlInjectionRule::class => 'sql_injection',
        XssRule::class => 'xss',
    ];

    #[Test]
    public function everyLeafOccurrenceConstantIsStillAPlainLiteralMatchingItsPin(): void
    {
        $root = self::projectRoot();
        $declarations = self::findLeafDeclarations($root);

        self::assertCount(
            self::EXPECTED_LEAF_COUNT,
            $declarations,
            \sprintf(
                "Expected exactly %d declarations shaped `const string SMELL_TYPE|PATTERN_TYPE = '<non-empty"
                . ' literal>\';` under src/ — the nine code-smell and three security families whose occurrence'
                . ' discriminator is that constant. Found %d: %s. A class that rewrites the constant as an'
                . ' expression drops out of this count instead of failing loudly elsewhere, which is exactly what'
                . ' this assertion exists to catch. If a family was deliberately added or removed, update this'
                . " expectation and FROZEN_LEAF together.\n",
                self::EXPECTED_LEAF_COUNT,
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
            $runtimeValue = $reflection->getConstant($declaration['constant']);

            if ($runtimeValue !== $declaration['literal']) {
                $mismatches[] = \sprintf(
                    '%s::%s evaluates to "%s" but its source text is the literal "%s" — the constant is no longer'
                    . ' a plain string literal.',
                    $class,
                    $declaration['constant'],
                    (string) $runtimeValue,
                    $declaration['literal'],
                );

                continue;
            }

            $expectedLeaf = self::FROZEN_LEAF[$class] ?? null;

            if ($expectedLeaf === null) {
                $mismatches[] = \sprintf(
                    '%s: declares %s but is not in FROZEN_LEAF — a family was added, removed or renamed without'
                    . ' updating the pin. If this is a deliberate new family, add it to FROZEN_LEAF with its'
                    . ' current leaf (not derived from NAME) and give it an'
                    . ' itKeysOccurrenceToItsOwnSmellType/PatternType pin test.',
                    $class,
                    $declaration['constant'],
                );

                continue;
            }

            if ($runtimeValue !== $expectedLeaf) {
                $mismatches[] = \sprintf(
                    '%s::%s is now "%s" but the frozen pin says "%s". This is a breaking change: it moves'
                    . ' `occurrence` under every already-accepted baseline entry, GitLab fingerprint and SARIF'
                    . ' partialFingerprints value for this finding. Do NOT "fix" this by re-pinning to match the'
                    . ' current value, and do NOT respell the constant to follow a renamed channel — the leaf'
                    . ' diverging from the channel code is the freeze working, not a drift to reconcile.',
                    $class,
                    $declaration['constant'],
                    $runtimeValue,
                    $expectedLeaf,
                );
            }
        }

        self::assertSame([], $mismatches, "\n" . implode("\n", $mismatches));
    }

    /**
     * The declaration and the pin map agreeing proves the constant did not
     * move; it does not prove the constant still reaches `occurrence`. That
     * is what the per-family `itKeysOccurrenceToItsOwnSmellType` /
     * `...PatternType` tests do, each running its own rule's `analyze()` and
     * comparing against a hand-written literal.
     *
     * So this asserts the pins exist and still name the frozen leaves. Both
     * halves matter: a family without a pin is a family whose constant could
     * stop reaching `occurrence` unnoticed (the state
     * `BooleanArgumentRule` and `CommandInjectionRule` were found in), and a
     * pin rewritten to expect the channel code instead of the leaf — the
     * shape a "make the base key on NAME" refactor would produce, green in
     * both the pins and the declaration checks above — is caught only by
     * comparing the pin's own asserted literal to this map.
     */
    #[Test]
    public function everyFrozenLeafIsAssertedByExactlyOnePinTest(): void
    {
        $asserted = self::findPinnedLeaves(self::projectRoot());
        $expected = array_count_values(array_values(self::FROZEN_LEAF));
        ksort($asserted);
        ksort($expected);

        self::assertSame(
            $expected,
            $asserted,
            'The leaf literals asserted by itKeysOccurrenceToItsOwnSmellType/PatternType pin tests no longer'
            . ' match FROZEN_LEAF one-for-one. A missing leaf means that family lost its pin (or never had one)'
            . ' and its discriminator can now move unnoticed; an unexpected leaf means a pin asserts something'
            . ' no family declares, so it protects nothing. Restore the pin rather than relaxing this'
            . " assertion.\n",
        );
    }

    /**
     * @return list<array{file: string, constant: string, literal: string}>
     */
    private static function findLeafDeclarations(string $root): array
    {
        $found = [];

        foreach (self::phpFilesIn($root . '/src') as $path) {
            $source = self::read($path);

            if (preg_match("/const string (SMELL_TYPE|PATTERN_TYPE) = '((?:[^'\\\\]|\\\\.)*)';/", $source, $match) !== 1) {
                continue;
            }

            // The two abstract bases declare the constant empty so that every
            // concrete subclass is forced to state its own; an empty leaf is a
            // placeholder, not a family.
            if ($match[2] === '') {
                continue;
            }

            $found[] = ['file' => $path, 'constant' => $match[1], 'literal' => stripcslashes($match[2])];
        }

        usort($found, static fn(array $a, array $b): int => $a['file'] <=> $b['file']);

        return $found;
    }

    /**
     * Every leaf literal a pin test asserts, counted. Read from test source
     * text the same bounded-window way the enumeration generator reads it:
     * find each pin method's declaration, then the one
     * `OccurrenceKey::semantic('<literal>'` call the naming convention places
     * near its start. A marker with no call in its window throws rather than
     * counting nothing, because a silent miss is indistinguishable from an
     * unprotected leaf.
     *
     * @return array<string, int>
     */
    private static function findPinnedLeaves(string $root): array
    {
        $window = 4000;
        $counts = [];

        foreach (self::phpFilesIn($root . '/tests') as $path) {
            $source = self::read($path);

            if (preg_match_all('/function itKeysOccurrenceToItsOwn(?:Smell|Pattern)Type\w*\(\)/', $source, $matches, \PREG_OFFSET_CAPTURE) === false) {
                throw new RuntimeException(\sprintf('Regex failure while scanning %s for pin methods.', $path));
            }

            foreach ($matches[0] as [$declaration, $pos]) {
                $slice = substr($source, $pos, $window);

                if (preg_match("/OccurrenceKey::semantic\\(\\s*\\n?\\s*'((?:[^'\\\\]|\\\\.)*)'/", $slice, $match) !== 1) {
                    throw new RuntimeException(\sprintf(
                        'Found pin method "%s" in %s but no OccurrenceKey::semantic(\'literal\') call within %d'
                        . ' characters of it. Widen the window or investigate the method — a silent miss here'
                        . ' would report a protected leaf as unprotected.',
                        $declaration,
                        $path,
                        $window,
                    ));
                }

                $leaf = stripcslashes($match[1]);
                $counts[$leaf] = ($counts[$leaf] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $directory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $paths[] = $fileInfo->getPathname();
            }
        }

        return $paths;
    }

    private static function read(string $path): string
    {
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $path));
        }

        return $source;
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
