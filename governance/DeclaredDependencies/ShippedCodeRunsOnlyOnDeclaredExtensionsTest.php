<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DeclaredDependencies;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether the PHP a consumer installs can call into an extension the install
 * is not guaranteed to have.
 *
 * The sibling control asks the same question about composer packages. This one
 * exists because the answer for extensions is reached differently and was
 * missing entirely: `composer.json` declared no `ext-*` at all while the
 * shipped tree called `token_get_all()`, `mb_strlen()`, `ctype_digit()`,
 * `filter_var()` and imported `XMLWriter`. A PHP built without mbstring,
 * ctype, tokenizer, xmlwriter or filter is a conforming PHP -- mbstring is not
 * even built by default -- so each of those is the same defect shape as the
 * undeclared `symfony/process`: code that works here and dies where it ships.
 * `tokenizer` carries duplication detection and LOC counting; `xmlwriter`
 * carries `--format=checkstyle`.
 *
 * Three channels, because an extension enters code three ways: a class, a call
 * into an internal function that no import records, and a constant. All three
 * are read from the parse by {@see ReachedNames}, which is also where the
 * reasoning about roles lives.
 *
 * PHP itself is asked which extension owns a name, rather than a list being
 * kept here. A list would be one more thing that falls quietly behind the code
 * it describes, and the failure would look like a green control.
 *
 * Asking PHP has its own failure, and it is the one this control was rebuilt
 * to close. `function_exists()` answers `false` both for a name that is not a
 * function and for a real call into an extension this build lacks, and the
 * control used to drop both. An `iconv_strlen()` on a PHP without iconv was
 * therefore invisible: the shipped tree reached an undeclared extension and
 * the control stayed green, which is the exact promise it was written to make.
 * So a name that cannot be attributed is now a REFUSAL naming it, the way the
 * sibling refuses a namespace no installed package owns. {@see PhpSurface} is
 * what makes "this runtime cannot resolve it" a statable, testable fact rather
 * than a branch that falls through.
 *
 * The refusals separate what a reader has to do about them:
 *
 * - a required extension this runtime does not load stops the judgement
 *   before it starts. Neither verdict would mean anything: a clean one because
 *   the missing surface shrinks what can be reached, and a stale-declaration
 *   one because it would advise removing a declaration that is correct.
 * - a name nothing in this PHP answers to is refused by name. Either an
 *   extension nobody declared provides it, or the shipped code names something
 *   that does not exist. Both are defects; neither is a skip.
 * - a name this PHP knows in another role is refused as an extractor defect,
 *   and is expected to be empty. It is kept because "cannot happen by
 *   construction" is a claim, and an unchecked claim is how the previous
 *   version of this control stayed green on the defect it existed to catch.
 *
 * On a floor for the number of attributed extensions, which was considered and
 * rejected: {@see self::itRefusesADeclarationForAnExtensionNothingReaches()}
 * already is one, derived rather than written down. It demands that every
 * `ext-` in `require` be reached, so a runtime resolving almost nothing fails
 * it by name instead of reporting a small clean set. A number next to it would
 * drift with the tree and assert less.
 *
 * An extension named under `suggest` is allowed: those are optional by design
 * and reached behind `extension_loaded()`, so requiring them would be wrong.
 * `ext-igbinary` is the live example. Removing that entry from `suggest`
 * without adding it to `require` reddens this control rather than passing
 * silently. A suggested extension this runtime does not load is not a
 * precondition failure -- `ext-parallel` is ZTS-only and loads almost nowhere
 * -- but if the tree reaches one of its names, that name is unattributable and
 * refused like any other, and the refusal names the declared extensions this
 * runtime is missing so the reader knows what to install.
 */
final class ShippedCodeRunsOnlyOnDeclaredExtensionsTest extends TestCase
{
    /**
     * Extensions that cannot be compiled out, so `require` need not name them.
     *
     * Everything else is an extension a conforming PHP may lack.
     */
    private const array ALWAYS_COMPILED_IN = ['core', 'standard', 'spl', 'pcre', 'date', 'reflection'];

    #[Test]
    public function itCallsIntoNoExtensionTheInstallDoesNotGuarantee(): void
    {
        $root = self::repositoryRoot();
        $surface = PhpSurface::ofThisProcess();

        self::assertSame([], self::unloadedRequirements($root, $surface), self::describeLeanRuntime($root, $surface));

        $verdict = self::judge(
            ReachedNames::globalsIn(ShippedTree::files($root)),
            $surface,
            self::declaredExtensions($root),
            $root,
        );

        self::assertSame([], $verdict['refusals'], implode(\PHP_EOL, $verdict['refusals']));
    }

    #[Test]
    public function itRefusesADeclarationForAnExtensionNothingReaches(): void
    {
        $root = self::repositoryRoot();
        $surface = PhpSurface::ofThisProcess();

        // Before any staleness is reported. A runtime missing a required
        // extension cannot reach that extension's names, so every correct
        // declaration for it would read as stale here -- and the advice would
        // be to delete the declaration, which is to introduce the very defect
        // this group exists to refuse.
        self::assertSame([], self::unloadedRequirements($root, $surface), self::describeLeanRuntime($root, $surface));

        $verdict = self::judge(
            ReachedNames::globalsIn(ShippedTree::files($root)),
            $surface,
            self::declaredExtensions($root),
            $root,
        );

        $stale = [];

        foreach (self::requiredExtensions($root) as $entry) {
            if (!\in_array($entry, $verdict['extensions'], true)) {
                $stale[] = $entry;
            }
        }

        self::assertSame(
            [],
            $stale,
            'composer.json requires extensions the shipped code never reaches: ' . implode(', ', $stale)
                . \PHP_EOL . 'A requirement nobody needs narrows where this installs for nothing.',
        );
    }

    /**
     * Proves the read judged a populated tree. A scan that attributed nothing
     * -- a walk that returned no file, a parser that returned no node, a
     * surface map read out of an empty extension list -- reports no refusal
     * either, and would pass for as long as it stayed broken.
     *
     * The anchors are named rather than counted, for the reason the sibling
     * gives: a count drifts with every file added to `src/`, while a tree that
     * stopped seeing tokenizer or xmlwriter has stopped seeing anything.
     */
    #[Test]
    public function itReadsTheShippedTreeItJudges(): void
    {
        $root = self::repositoryRoot();
        $verdict = self::judge(
            ReachedNames::globalsIn(ShippedTree::files($root)),
            PhpSurface::ofThisProcess(),
            self::declaredExtensions($root),
            $root,
        );

        foreach (['ext-tokenizer', 'ext-xmlwriter', 'ext-json', 'ext-mbstring'] as $anchor) {
            self::assertContains($anchor, $verdict['extensions'], \sprintf(
                'Attributed nothing to %s, so the scan is reading less than the tree contains.',
                $anchor,
            ));
        }
    }

    /**
     * The defect this rebuild exists for, planted twice over.
     *
     * A PHP without mbstring is simulated by subtracting exactly what mbstring
     * declares -- read out of the extension, never typed out -- so this runs
     * on a runtime that has mbstring and still asks what a runtime without it
     * would say. The old control answered "nothing reached mbstring" and went
     * green. This one has to name `mb_strlen`.
     */
    #[Test]
    public function itRefusesANameALeanRuntimeCannotResolve(): void
    {
        $root = self::repositoryRoot();
        $lean = PhpSurface::ofThisProcess()->without('mbstring');

        $refusals = self::judge(
            [['file' => $root . '/src/Planted.php', 'name' => 'mb_strlen', 'role' => ReachedNames::FUNCTION]],
            $lean,
            self::declaredExtensions($root),
            $root,
        )['refusals'];

        self::assertCount(1, $refusals, implode(\PHP_EOL, $refusals));
        self::assertStringContainsString('src/Planted.php', $refusals[0]);
        self::assertStringContainsString('mb_strlen', $refusals[0]);
        self::assertStringContainsString('nothing in this PHP answers to', $refusals[0]);
    }

    /**
     * The same defect without any simulation: a call into an extension this
     * runtime genuinely does not carry. `enchant_broker_init()` belongs to
     * ext-enchant, which is not loaded here and is not declared anywhere, so
     * the real surface has to refuse it on its own.
     */
    #[Test]
    public function itRefusesACallIntoAnExtensionThisRuntimeDoesNotCarry(): void
    {
        $root = self::repositoryRoot();
        $surface = PhpSurface::ofThisProcess();

        self::assertFalse($surface->loads('enchant'), 'This PHP loads ext-enchant, so it cannot stand in for one that does not.');

        $refusals = self::judge(
            [['file' => $root . '/src/Planted.php', 'name' => 'enchant_broker_init', 'role' => ReachedNames::FUNCTION]],
            $surface,
            self::declaredExtensions($root),
            $root,
        )['refusals'];

        self::assertCount(1, $refusals, implode(\PHP_EOL, $refusals));
        self::assertStringContainsString('enchant_broker_init', $refusals[0]);
        self::assertStringContainsString('enchant_', $refusals[0], 'The refusal should hand over the prefix as a lead.');
    }

    /**
     * A name that is reached and attributable, but whose extension nothing
     * declares. This is the shape the control was always meant to catch, and
     * it has to keep working now that unattributable names are refused too --
     * otherwise every refusal would collapse into one undifferentiated bucket.
     */
    #[Test]
    public function itRefusesAnExtensionNoDeclarationCovers(): void
    {
        $root = self::repositoryRoot();

        $refusals = self::judge(
            [['file' => $root . '/src/Planted.php', 'name' => 'iconv_strlen', 'role' => ReachedNames::FUNCTION]],
            PhpSurface::ofThisProcess(),
            ['ext-json' => true],
            $root,
        )['refusals'];

        self::assertCount(1, $refusals, implode(\PHP_EOL, $refusals));
        self::assertStringContainsString('ext-iconv', $refusals[0]);
        self::assertStringContainsString('neither requires nor suggests', $refusals[0]);
    }

    /**
     * A name this PHP knows, but as something other than the parse said. The
     * bucket is expected to be empty on the real tree; it is asserted here so
     * that "the extractor cannot mis-role a name" stays a checked claim.
     */
    #[Test]
    public function itRefusesANameItReachedInTheWrongRole(): void
    {
        $root = self::repositoryRoot();

        $refusals = self::judge(
            [['file' => $root . '/src/Planted.php', 'name' => 'XMLWriter', 'role' => ReachedNames::FUNCTION]],
            PhpSurface::ofThisProcess(),
            self::declaredExtensions($root),
            $root,
        )['refusals'];

        self::assertCount(1, $refusals, implode(\PHP_EOL, $refusals));
        self::assertStringContainsString('as a function', $refusals[0]);
        self::assertStringContainsString('narrow the extractor', $refusals[0]);
    }

    /**
     * The other half of the pair: a tree whose every name is attributable and
     * declared produces no refusal, so the cases above are evidence of what
     * this control rejects rather than of a control that rejects everything.
     */
    #[Test]
    public function itAcceptsATreeWhoseNamesAreAllDeclared(): void
    {
        $root = self::repositoryRoot();

        $verdict = self::judge(
            [
                ['file' => $root . '/src/Planted.php', 'name' => 'mb_strlen', 'role' => ReachedNames::FUNCTION],
                ['file' => $root . '/src/Planted.php', 'name' => 'XMLWriter', 'role' => ReachedNames::CLASS_LIKE],
                ['file' => $root . '/src/Planted.php', 'name' => 'T_COMMENT', 'role' => ReachedNames::CONSTANT],
                ['file' => $root . '/src/Planted.php', 'name' => 'strlen', 'role' => ReachedNames::FUNCTION],
            ],
            PhpSurface::ofThisProcess(),
            ['ext-mbstring' => true, 'ext-xmlwriter' => true, 'ext-tokenizer' => true],
            $root,
        );

        self::assertSame([], $verdict['refusals'], implode(\PHP_EOL, $verdict['refusals']));
        self::assertSame(['ext-mbstring', 'ext-tokenizer', 'ext-xmlwriter'], $verdict['extensions']);
    }

    /**
     * And the precondition itself: a runtime missing a required extension is
     * refused before either verdict is reached.
     */
    #[Test]
    public function itRefusesToJudgeOnARuntimeMissingARequiredExtension(): void
    {
        $root = self::repositoryRoot();
        $lean = PhpSurface::ofThisProcess()->without('tokenizer');

        self::assertSame(['ext-tokenizer'], self::unloadedRequirements($root, $lean));
        self::assertStringContainsString('ext-tokenizer', self::describeLeanRuntime($root, $lean));
        self::assertStringContainsString('Install them and re-run', self::describeLeanRuntime($root, $lean));
        self::assertSame([], self::unloadedRequirements($root, PhpSurface::ofThisProcess()));
    }

    /**
     * Judges one list of reached global names against one surface and one set
     * of declarations.
     *
     * @param list<array{file: string, name: string, role: string}> $reached
     * @param array<string, true> $declared `ext-` entry => true, from require and suggest
     *
     * @return array{refusals: list<string>, extensions: list<string>}
     */
    private static function judge(array $reached, PhpSurface $surface, array $declared, string $treeRoot): array
    {
        $missing = [];

        foreach (array_keys($declared) as $entry) {
            if (!$surface->loads(substr($entry, 4))) {
                $missing[] = $entry;
            }
        }

        sort($missing);

        $refusals = [];
        $extensions = [];

        foreach ($reached as $record) {
            $name = $record['name'];
            $where = str_starts_with($record['file'], $treeRoot . '/')
                ? substr($record['file'], \strlen($treeRoot) + 1)
                : $record['file'];

            $owner = self::attribute($surface, $name, $record['role']);

            if ($owner !== null) {
                if (\in_array(strtolower($owner), self::ALWAYS_COMPILED_IN, true)) {
                    continue;
                }

                $entry = 'ext-' . strtolower($owner);
                $extensions[$entry] = true;

                if (!isset($declared[$entry])) {
                    $refusals[] = \sprintf(
                        '%s reaches %s, which composer.json neither requires nor suggests — add %s to require, or, if it is optional and guarded by extension_loaded(), to suggest.',
                        $where,
                        self::spell($name, $record['role']),
                        $entry,
                    );
                }

                continue;
            }

            if (self::knownInSomeRole($surface, $name)) {
                if (self::knownInRole($surface, $name, $record['role'])) {
                    // Resolves in the role the parse gave it, but belongs to no
                    // extension: a Composer package's function or one of this
                    // tree's own. Nobody's `ext-` declaration covers it and
                    // none should.
                    continue;
                }

                $refusals[] = \sprintf(
                    '%s reaches %s as a %s, but this PHP knows that name as a %s — the extractor placed it in the wrong role; narrow the extractor rather than the declarations.',
                    $where,
                    $name,
                    $record['role'],
                    self::roleThisPhpKnows($surface, $name),
                );

                continue;
            }

            $refusals[] = self::describeUnresolvable($where, $name, $record['role'], $missing);
        }

        sort($refusals);
        $attributed = array_keys($extensions);
        sort($attributed);

        return ['refusals' => $refusals, 'extensions' => $attributed];
    }

    /**
     * @param list<string> $declaredButUnloaded
     */
    private static function describeUnresolvable(string $where, string $name, string $role, array $declaredButUnloaded): string
    {
        $lead = \sprintf(
            '%s reaches %s, and nothing in this PHP answers to that name — either an extension nobody declared provides it, or the shipped code names something that does not exist.',
            $where,
            self::spell($name, $role),
        );

        $prefix = self::family($name);

        if ($prefix !== null) {
            $lead .= \sprintf(' Names beginning %s usually come from one extension; that is a lead, not the verdict.', $prefix);
        }

        // Named as a fact, not as the recommended action. `ext-parallel` is
        // ZTS-only and loads almost nowhere, so a clause that read "install
        // these and re-run" would fire on every unresolvable name forever and
        // would dress a true positive up as a lean runtime.
        if ($declaredButUnloaded !== []) {
            $lead .= \sprintf(
                ' This PHP also does not load extensions composer.json declares (%s); if the name belongs to one of those, install it and re-run before reading this as a defect in the tree.',
                implode(', ', $declaredButUnloaded),
            );
        }

        return $lead;
    }

    private static function family(string $name): ?string
    {
        $underscore = strpos($name, '_');

        if ($underscore === false || $underscore < 2) {
            return null;
        }

        return substr($name, 0, $underscore + 1);
    }

    private static function spell(string $name, string $role): string
    {
        return $role === ReachedNames::FUNCTION ? $name . '()' : $name;
    }

    private static function attribute(PhpSurface $surface, string $name, string $role): ?string
    {
        return match ($role) {
            ReachedNames::FUNCTION => $surface->functionExtension($name),
            ReachedNames::CLASS_LIKE => $surface->classExtension($name),
            default => $surface->constantExtension($name),
        };
    }

    private static function knownInRole(PhpSurface $surface, string $name, string $role): bool
    {
        return match ($role) {
            ReachedNames::FUNCTION => $surface->knowsAsFunction($name),
            ReachedNames::CLASS_LIKE => $surface->knowsAsClass($name),
            default => $surface->knowsAsConstant($name),
        };
    }

    private static function knownInSomeRole(PhpSurface $surface, string $name): bool
    {
        return $surface->knowsAsFunction($name) || $surface->knowsAsClass($name) || $surface->knowsAsConstant($name);
    }

    private static function roleThisPhpKnows(PhpSurface $surface, string $name): string
    {
        if ($surface->knowsAsFunction($name)) {
            return ReachedNames::FUNCTION;
        }

        return $surface->knowsAsClass($name) ? ReachedNames::CLASS_LIKE : ReachedNames::CONSTANT;
    }

    /**
     * @return list<string> required `ext-` entries this runtime does not load
     */
    private static function unloadedRequirements(string $root, PhpSurface $surface): array
    {
        $missing = [];

        foreach (self::requiredExtensions($root) as $entry) {
            if (!$surface->loads(substr($entry, 4))) {
                $missing[] = $entry;
            }
        }

        sort($missing);

        return $missing;
    }

    private static function describeLeanRuntime(string $root, PhpSurface $surface): string
    {
        return 'This PHP does not load extensions composer.json requires: '
            . implode(', ', self::unloadedRequirements($root, $surface)) . \PHP_EOL
            . 'Neither verdict of this control would mean anything here: a clean one because the missing surface shrinks'
            . ' what the shipped tree can be seen to reach, and a stale-declaration one because it would advise removing'
            . ' a declaration that is correct. Install them and re-run.';
    }

    /**
     * @return list<string> `ext-` entries from require
     */
    private static function requiredExtensions(string $root): array
    {
        $required = ShippedTree::manifest($root)['require'] ?? [];
        self::assertIsArray($required);

        return self::extensionEntries($required);
    }

    /**
     * @return array<string, true> `ext-` entries from require and suggest
     */
    private static function declaredExtensions(string $root): array
    {
        $manifest = ShippedTree::manifest($root);
        $required = $manifest['require'] ?? [];
        $suggested = $manifest['suggest'] ?? [];

        self::assertIsArray($required);
        self::assertIsArray($suggested);

        $declared = [];

        foreach ([...self::extensionEntries($required), ...self::extensionEntries($suggested)] as $entry) {
            $declared[$entry] = true;
        }

        return $declared;
    }

    /**
     * @param array<mixed> $section
     *
     * @return list<string>
     */
    private static function extensionEntries(array $section): array
    {
        $entries = [];

        foreach (array_keys($section) as $entry) {
            if (\is_string($entry) && str_starts_with($entry, 'ext-')) {
                $entries[] = $entry;
            }
        }

        sort($entries);

        return $entries;
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
