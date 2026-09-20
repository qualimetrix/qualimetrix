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
 * reasoning about roles, and the two shapes it cannot see, live: a name
 * spelled in a string, and a class constant one extension adds to another's
 * class. Neither is an oversight and neither is reachable from this tree
 * today, but both mean a green verdict is a statement about the names that
 * are written as names.
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
 * - a name this PHP answers from a file rather than from an extension is
 *   refused too. That is what a polyfill looks like from inside a process:
 *   `symfony/polyfill-mbstring` is in this project's production closure and
 *   defines a global `mb_strlen()`, so on a build without mbstring the
 *   polyfill would answer, the extension would go unattributed, and accepting
 *   that silently would be the original defect with one more step in it.
 * - a name this PHP knows only in another role is refused. The shipped code
 *   may be calling something that is not callable, or this reader may have
 *   mis-roled it; the refusal says both rather than blaming one.
 * - a function or constant the tree declares under a name PHP already uses is
 *   refused at the declaration. Such a name makes every unqualified call in
 *   its namespace ambiguous to any static reader, so it would otherwise
 *   silence a real reach rather than be reported.
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
     * Extensions no build omits *and* that Composer's platform conventions
     * leave undeclared, so `require` naming them would be noise.
     *
     * This is not the same set as "cannot be compiled out". `json` and `hash`
     * cannot be either, on any PHP this project supports, but both are named
     * in `require` and both stay judged here — which is the safer direction,
     * and moving them into this list would make
     * {@see self::itRefusesADeclarationForAnExtensionNothingReaches()} call
     * two correct declarations stale.
     *
     * Everything outside this list is an extension a conforming PHP may lack.
     */
    private const array ALWAYS_COMPILED_IN = ['core', 'standard', 'spl', 'pcre', 'date', 'reflection', 'random'];

    #[Test]
    public function itCallsIntoNoExtensionTheInstallDoesNotGuarantee(): void
    {
        $root = self::repositoryRoot();
        $surface = PhpSurface::ofThisProcess();

        self::assertSame([], self::unloadedRequirements($root, $surface), self::describeLeanRuntime($root, $surface));

        $verdict = self::judge(
            ReachedNames::globalsIn(ShippedTree::files($root)),
            ReachedNames::declarationsIn(ShippedTree::files($root)),
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
            ReachedNames::declarationsIn(ShippedTree::files($root)),
            $surface,
            self::declaredExtensions($root),
            $root,
        );

        // A refusal means some name was not attributed, and an unattributed
        // name is indistinguishable from an extension nothing reaches. Reading
        // staleness past one would advise deleting a declaration on the
        // strength of a name this control admits it could not place.
        self::assertSame(
            [],
            $verdict['refusals'],
            'The judgement is incomplete, so staleness cannot be read from it yet:' . \PHP_EOL
                . implode(\PHP_EOL, $verdict['refusals']),
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
        $surface = PhpSurface::ofThisProcess();

        // Without this, a runner missing a required extension fails here with
        // "attributed nothing to ext-tokenizer", which reads as a broken scan
        // rather than as the lean runtime it is.
        self::assertSame([], self::unloadedRequirements($root, $surface), self::describeLeanRuntime($root, $surface));

        $verdict = self::judge(
            ReachedNames::globalsIn(ShippedTree::files($root)),
            ReachedNames::declarationsIn(ShippedTree::files($root)),
            $surface,
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
            [],
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
            [],
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

        // `json` rather than a more exotic extension on purpose: this case is
        // about the declaration being absent, and an extension the runner
        // might not load would turn it into the unattributable case instead,
        // passing for the wrong reason.
        $refusals = self::judge(
            [['file' => $root . '/src/Planted.php', 'name' => 'json_encode', 'role' => ReachedNames::FUNCTION]],
            [],
            PhpSurface::ofThisProcess(),
            ['ext-mbstring' => true],
            $root,
        )['refusals'];

        self::assertCount(1, $refusals, implode(\PHP_EOL, $refusals));
        self::assertStringContainsString('ext-json', $refusals[0]);
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
            [],
            PhpSurface::ofThisProcess(),
            self::declaredExtensions($root),
            $root,
        )['refusals'];

        self::assertCount(1, $refusals, implode(\PHP_EOL, $refusals));
        self::assertStringContainsString('as a function', $refusals[0]);
        self::assertStringContainsString('only as a class', $refusals[0]);
    }

    /**
     * The defect a first pass of this rebuild still had: a global name that
     * resolves, but to a file rather than to an extension.
     *
     * `symfony/polyfill-intl-grapheme` is in this project's production closure
     * and defines a global `grapheme_strrev()`, so no simulation is needed —
     * this is what every polyfill looks like from inside a process. Accepting
     * it because "something answers to the name" is the original
     * `function_exists()` ambiguity with one more step in it: on a build
     * without the extension, the polyfill answers and the extension goes
     * unattributed under a green control.
     */
    #[Test]
    public function itRefusesAGlobalNameOnlyAPackageAnswers(): void
    {
        $root = self::repositoryRoot();
        $surface = PhpSurface::ofThisProcess();

        self::assertTrue($surface->knowsAsFunction('grapheme_strrev'), 'The witness has to be a name this process answers.');
        self::assertNull($surface->functionExtension('grapheme_strrev'), 'The witness has to belong to no extension.');

        $refusals = self::judge(
            [['file' => $root . '/src/Planted.php', 'name' => 'grapheme_strrev', 'role' => ReachedNames::FUNCTION]],
            [],
            $surface,
            self::declaredExtensions($root),
            $root,
        )['refusals'];

        self::assertCount(1, $refusals, implode(\PHP_EOL, $refusals));
        self::assertStringContainsString('grapheme_strrev', $refusals[0]);
        self::assertStringContainsString('polyfill-intl-grapheme', $refusals[0], 'The refusal has to name the file that answered.');
        self::assertStringContainsString('declare the extension', $refusals[0]);
    }

    /**
     * A declaration of the tree's own that collides with a name PHP provides.
     *
     * The shadow filter has to be tree-wide, because nothing static can say
     * which files a call has loaded — so such a declaration would otherwise
     * silence every unqualified use of PHP's name, across files, including
     * one guarded by `if (false)`. Refusing the collision is what keeps the
     * approximation from becoming a silent drop.
     */
    #[Test]
    public function itRefusesADeclarationThatShadowsANamePhpProvides(): void
    {
        $root = self::repositoryRoot();

        $refusals = self::judge(
            [],
            [['file' => $root . '/src/Planted.php', 'name' => 'token_get_all', 'role' => ReachedNames::FUNCTION]],
            PhpSurface::ofThisProcess(),
            self::declaredExtensions($root),
            $root,
        )['refusals'];

        self::assertCount(1, $refusals, implode(\PHP_EOL, $refusals));
        self::assertStringContainsString('token_get_all', $refusals[0]);
        self::assertStringContainsString('ext-tokenizer', $refusals[0]);
        self::assertStringContainsString('rename it', $refusals[0]);
    }

    /**
     * And the legitimate half of the same mechanism, read end to end rather
     * than handed to `judge()` pre-shaped: a tree that declares its own
     * function and calls it unqualified reaches no global name, while a
     * constant of the same name is untouched by that declaration. Checking a
     * function against a declared constant, or the reverse, loses the reach
     * entirely — which is the silent drop this whole control exists against.
     */
    #[Test]
    public function itSeparatesDeclaredFunctionsFromDeclaredConstants(): void
    {
        $tree = self::plantedTree(<<<'PLANTED'
            <?php

            namespace Planted;

            function helper(): void {}

            const T_COMMENT = 1;

            final class Uses
            {
                public function go(string $source): array
                {
                    helper();

                    return token_get_all($source);
                }
            }
            PLANTED);

        $globals = ReachedNames::globalsIn([$tree . '/Planted.php']);
        $names = array_map(static fn(array $record): string => $record['role'] . ':' . $record['name'], $globals);

        self::assertNotContains('function:helper', $names, "The tree's own function is not a global reach.");
        self::assertNotContains('constant:T_COMMENT', $names, "The tree's own constant is not a global reach.");
        self::assertContains('function:token_get_all', $names, 'A real call must survive a same-named constant declaration.');
    }

    private static function plantedTree(string $source): string
    {
        $directory = sys_get_temp_dir() . '/qmx-declared-extensions-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o777, true), $directory);
        self::assertIsInt(file_put_contents($directory . '/Planted.php', $source));

        register_shutdown_function(static function () use ($directory): void {
            @unlink($directory . '/Planted.php');
            @rmdir($directory);
        });

        return $directory;
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
            [],
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
     * @param list<array{file: string, name: string, role: string}> $declarations global names the tree declares itself
     * @param array<string, true> $declared `ext-` entry => true, from require and suggest
     *
     * @return array{refusals: list<string>, extensions: list<string>}
     */
    private static function judge(
        array $reached,
        array $declarations,
        PhpSurface $surface,
        array $declared,
        string $treeRoot,
    ): array {
        $missing = [];

        foreach (array_keys($declared) as $entry) {
            if (!$surface->loads(substr($entry, 4))) {
                $missing[] = $entry;
            }
        }

        sort($missing);

        $refusals = [];
        $extensions = [];

        foreach ($declarations as $declaration) {
            $owner = self::attribute($surface, $declaration['name'], $declaration['role']);

            if ($owner === null) {
                continue;
            }

            $refusals[] = \sprintf(
                '%s declares a %s named %s, which PHP already provides from %s. In its own namespace that declaration wins every unqualified use, and no static reader can tell which files a given call has loaded — so rename it, or qualify the uses.',
                self::relative($declaration['file'], $treeRoot),
                $declaration['role'],
                $declaration['name'],
                'ext-' . $owner,
            );
        }

        foreach ($reached as $record) {
            $name = $record['name'];
            $where = self::relative($record['file'], $treeRoot);

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

            if (self::knownInRole($surface, $name, $record['role'])) {
                $refusals[] = self::describeUserlandAnswer($where, $name, $record['role'], $surface);

                continue;
            }

            if (self::knownInSomeRole($surface, $name)) {
                $refusals[] = \sprintf(
                    '%s reaches %s as a %s, but this PHP knows that name only as a %s — either the shipped code uses it in a way it does not support, or this reader gave it the wrong role.',
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
     * A name this process answers, but from a file rather than from an
     * extension. A polyfill is exactly this, and it is the shape that would
     * otherwise let a missing extension pass unnoticed.
     */
    private static function describeUserlandAnswer(string $where, string $name, string $role, PhpSurface $surface): string
    {
        $file = $surface->definingFile($name);

        return \sprintf(
            '%s reaches %s, which this PHP answers from %s rather than from any extension.'
                . ' If that file polyfills an extension, this build lacks the extension it stands in for and the polyfill is hiding it — declare the extension.'
                . ' Otherwise a Composer package provides a global name, which is a dependency neither control in this group can attribute.',
            $where,
            self::spell($name, $role),
            $file ?? 'somewhere this control cannot locate',
        );
    }

    private static function relative(string $file, string $treeRoot): string
    {
        return str_starts_with($file, $treeRoot . '/') ? substr($file, \strlen($treeRoot) + 1) : $file;
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
