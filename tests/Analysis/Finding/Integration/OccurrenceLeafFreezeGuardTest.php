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
use ReflectionMethod;
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
 *
 * Source text is not execution, though, so the pins it finds are additionally
 * held to being tests PHPUnit runs — see
 * {@see everyPinTestIsRegisteredWithPhpunitAndNotJustDeclared()}. Nothing here
 * compares a frozen constant to a rule's `NAME`, by design.
 */
final class OccurrenceLeafFreezeGuardTest extends TestCase
{
    private const int EXPECTED_LEAF_COUNT = 12;

    private const string DISCOVERY_ARTIFACT = 'docs/internal/generated/modular-architecture/test-phpunit-discovery.txt';

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
        $asserted = array_count_values(array_column(self::findPinTests(self::projectRoot()), 'leaf'));
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
     * The two assertions above read the pins out of SOURCE TEXT, and source
     * text is not execution: a pin method that has lost its `#[Test]`
     * attribute still reads exactly like a pin, so the freeze would keep
     * reporting the leaf as protected while nothing re-runs the rule. That is
     * a one-line, review-sized edit, and it was measured: dropping `#[Test]`
     * from EvalRuleTest's pin left both assertions above green and its own
     * class green while `AbstractCodeSmellRule` keyed `occurrence` on `NAME`.
     *
     * So every pin the scan finds must also be a test PHPUnit actually runs,
     * and that is checked twice because neither half covers the other:
     *
     * - Reflection proves the method is executable AS A TEST — public,
     *   non-static, on a concrete `TestCase`, carrying `#[Test]`. This is the
     *   half that reddens under `vendor/bin/phpunit` alone.
     * - The generated discovery listing proves PHPUnit's own run enrols the
     *   class, which reflection cannot see: a test file outside every suite of
     *   `phpunit.xml.dist` reflects perfectly and is never executed. That file
     *   is a snapshot, so it lags an edit made after it was generated —
     *   `composer architecture:check` is what holds it fresh, and this
     *   assertion says so in its failure text rather than pretending the
     *   snapshot is live.
     *
     * This checks REGISTRATION only. It deliberately says nothing about what a
     * pin asserts, and in particular never compares a constant to a rule's
     * `NAME`: after the final rename step the leaf and the channel code read
     * differently on purpose.
     */
    #[Test]
    public function everyPinTestIsRegisteredWithPhpunitAndNotJustDeclared(): void
    {
        $root = self::projectRoot();
        $pins = self::findPinTests($root);
        $discovered = self::discoveredTestNames($root);
        $unregistered = [];

        foreach ($pins as $pin) {
            $name = $pin['class'] . '::' . $pin['method'];

            if (!class_exists($pin['class'])) {
                $unregistered[] = \sprintf('%s: %s is not a loadable class.', $pin['file'], $pin['class']);

                continue;
            }

            $reflection = new ReflectionClass($pin['class']);

            if ($reflection->isAbstract() || !$reflection->isSubclassOf(TestCase::class)) {
                $unregistered[] = \sprintf(
                    '%s declares a pin but is not a concrete PHPUnit TestCase, so the pin never runs.',
                    $pin['class'],
                );

                continue;
            }

            if (!$reflection->hasMethod($pin['method'])) {
                $unregistered[] = \sprintf(
                    '%s: the pin text is in the file but %s is not a method of the declared class — the file'
                    . ' declares more than one class, or the scan read the wrong one.',
                    $pin['file'],
                    $name,
                );

                continue;
            }

            $method = new ReflectionMethod($pin['class'], $pin['method']);

            if (!$method->isPublic() || $method->isStatic() || $method->getAttributes(Test::class) === []) {
                $unregistered[] = \sprintf(
                    '%s is not runnable as a PHPUnit test (public: %s, static: %s, #[Test]: %s). The pin still'
                    . ' reads like a pin to the text scan above, so the leaf would be reported as protected'
                    . ' while nothing re-executes the rule.',
                    $name,
                    $method->isPublic() ? 'yes' : 'no',
                    $method->isStatic() ? 'yes' : 'no',
                    $method->getAttributes(Test::class) === [] ? 'absent' : 'present',
                );

                continue;
            }

            if (!isset($discovered[$name])) {
                $unregistered[] = \sprintf(
                    '%s is not listed in %s. Either the class sits outside every suite of phpunit.xml.dist and'
                    . ' PHPUnit never runs it, or that generated listing is stale — regenerate it with'
                    . ' `composer architecture:check` and read this again.',
                    $name,
                    self::DISCOVERY_ARTIFACT,
                );
            }
        }

        self::assertSame(
            [],
            $unregistered,
            "\nA pin found by the source scan is not a test PHPUnit runs, so the leaf it names is unprotected"
            . " despite looking pinned:\n" . implode("\n", $unregistered) . "\n",
        );

        self::assertCount(
            self::EXPECTED_LEAF_COUNT,
            $pins,
            \sprintf(
                'Expected the scan to find exactly %d pin tests, one per frozen leaf, but it found %d. The'
                . ' pin-to-leaf comparison above already rejects a wrong population; this states the count on'
                . " its own so a failure here reads as a number rather than as a diff of two maps.\n",
                self::EXPECTED_LEAF_COUNT,
                \count($pins),
            ),
        );
    }

    /**
     * The test names PHPUnit's own `--list-tests` produced when the modular
     * architecture artifacts were last generated, as a set. Names of cases fed
     * by a data provider carry a trailing quoted label; pin tests take no
     * arguments, so the bare `Class::method` form is the whole name.
     *
     * @return array<string, true>
     */
    private static function discoveredTestNames(string $root): array
    {
        $path = $root . '/' . self::DISCOVERY_ARTIFACT;
        $names = [];

        foreach (explode("\n", self::read($path)) as $line) {
            if (!str_starts_with($line, ' - ')) {
                continue;
            }

            $names[rtrim(substr($line, 3))] = true;
        }

        if ($names === []) {
            throw new RuntimeException(\sprintf(
                '%s lists no test at all. It is generated from `phpunit --list-tests`; regenerate it with'
                . ' `composer architecture:check` before reading a verdict out of it.',
                self::DISCOVERY_ARTIFACT,
            ));
        }

        return $names;
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
     * Every pin test the tests tree declares: the class and method that
     * declare it, and the leaf literal it asserts. Read from test source text
     * the same bounded-window way the enumeration generator reads it: find
     * each pin method's declaration, then the one
     * `OccurrenceKey::semantic('<literal>'` call the naming convention places
     * near its start. A marker with no call in its window throws rather than
     * counting nothing, because a silent miss is indistinguishable from an
     * unprotected leaf.
     *
     * @return list<array{file: string, class: string, method: string, leaf: string}>
     */
    private static function findPinTests(string $root): array
    {
        $window = 4000;
        $pins = [];

        foreach (self::phpFilesIn($root . '/tests') as $path) {
            $source = self::read($path);

            if (preg_match_all('/function (itKeysOccurrenceToItsOwn(?:Smell|Pattern)Type\w*)\(\)/', $source, $matches, \PREG_OFFSET_CAPTURE) === false) {
                throw new RuntimeException(\sprintf('Regex failure while scanning %s for pin methods.', $path));
            }

            foreach ($matches[0] as $index => [$declaration, $pos]) {
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

                $pins[] = [
                    'file' => $path,
                    'class' => self::classFromTestSource($source, $path),
                    'method' => $matches[1][$index][0],
                    'leaf' => stripcslashes($match[1]),
                ];
            }
        }

        usort($pins, static fn(array $a, array $b): int => [$a['class'], $a['method']] <=> [$b['class'], $b['method']]);

        return $pins;
    }

    /**
     * The declaring class of a test file, parsed from the same source text the
     * pin scan already holds rather than mapped from the path: `tests/` is not
     * required to mirror `src/`, and a wrong mapping here would silently drop
     * a pin from the registration check below.
     */
    private static function classFromTestSource(string $source, string $path): string
    {
        if (
            preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1
            || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $class) !== 1
        ) {
            throw new RuntimeException(\sprintf(
                'Could not read a namespace and a class declaration out of %s, which declares a pin method.',
                $path,
            ));
        }

        return trim($namespace[1]) . '\\' . $class[1];
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
