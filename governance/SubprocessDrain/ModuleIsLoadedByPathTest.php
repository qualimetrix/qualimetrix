<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SubprocessDrain;

use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

/**
 * Every file that calls the subprocess module must also `require_once` it by
 * path.
 *
 * The module is loaded two ways on purpose, and both are load-bearing: the
 * namespace is declared in `composer.json`'s `autoload-dev.psr-4` so that the
 * ban on production code importing a development namespace can see it at all,
 * and each caller requires the file by path so that an isolated scratch project
 * executes *its own* copy. Those projects symlink `vendor/`, so an autoloaded
 * class resolves through that symlink's `autoload_psr4.php` back to this tree,
 * and a deliberately broken scratch copy is never read — the negative control
 * then passes for a reason nobody chose.
 *
 * The second half is a convention nothing enforced, and a convention nothing
 * enforces is a claim about a set that drifts: four callers had already stopped
 * carrying it while three documents still said every caller did. This control
 * is that enforcement.
 *
 * ## What counts as a caller
 *
 * A textual occurrence of the call outside a comment token. Two consequences
 * are deliberate:
 *
 * - A `{@see ChildProcess::run()}` in a docblock is not a caller. That spelling
 *   exists in the tree, and this file carries one too, so the exemption is
 *   measured here rather than assumed: this file is asserted *out* of the
 *   caller set below.
 * - The call written inside a string literal — source text a child will run —
 *   *is* a caller, because that child needs the module on disk exactly as this
 *   process does.
 *
 * ## What counts as loading it by path
 *
 * A `require_once` whose expression resolves, by computation rather than by
 * spelling, to the module's realpath. `require` is not accepted: the module
 * defines a class, so a second `require` of it is a fatal redeclaration, and
 * accepting it here would bless a form that only works once.
 *
 * The resolver understands `__DIR__` and `dirname(__DIR__, N)` concatenated
 * with one string literal, which is every form in the tree. Anything else is
 * *refused*, not passed: a path assembled from a variable cannot be resolved
 * without executing the file, and a control that shrugged at what it cannot
 * read would pass the one form nobody has checked.
 *
 * ## Named gaps
 *
 * An aliased import (`use …\ChildProcess as Child; Child::run(…)`) is invisible
 * to a textual needle, and so is a call reached through a variable class name.
 * Neither spelling exists in the tree today; the enumeration swept for them.
 *
 * A differently-cased spelling is *not* a gap: the scan folds case, because PHP
 * resolves class and method names without regard to it. The tree spells every
 * call in the class's own casing today, so the fold changes no answer here and
 * is measured on text this control writes instead.
 *
 * The comment exemption is unchanged, and it now reaches a docblock whatever
 * casing it uses. That widens the set of texts it could excuse rather than
 * anything it does excuse today — the assertion keeping this file out of the
 * caller set predates the fold and is not evidence for it.
 */
final class ModuleIsLoadedByPathTest extends TestCase
{
    private const MODULE_PATH = 'scripts/subprocess/ChildProcess.php';

    /**
     * Lower case, because the scan folds the file it reads: PHP resolves class
     * and method names without regard to case, so `childprocess::Run(` is a
     * working call and a case-sensitive needle would not see it. Folding adds
     * no caller to the tree today — measured — so it costs nothing and closes
     * a spelling.
     *
     * Spelled in halves for the same reason the sibling control spells its own
     * needles in halves: this file is in the scanned population, and a whole
     * spelling in a string literal here would make this control a caller of
     * the module it is judging. The fold widens what counts as whole: any
     * casing does now, not only the class's own.
     */
    private const CALL_NEEDLE = 'childprocess' . '::run(';

    public static function tearDownAfterClass(): void
    {
        PhpFilePopulation::forget();
    }

    /**
     * A control over a set that came back empty is green for the wrong reason.
     * These four are the cheapest evidence that the caller scan is looking
     * where it claims and excusing only what it means to excuse.
     */
    #[Test]
    public function itFindsCallersAcrossTheTreeAndExcusesDocumentation(): void
    {
        $callers = self::callers();

        self::assertContains(
            'governance/SubprocessDrain/PhpFilePopulation.php',
            $callers,
            'This group\'s own caller is missing from the scan, so the needle or the population is broken and '
            . 'every assertion below would pass over nothing.',
        );

        $outsideThisGroup = array_values(array_filter(
            $callers,
            static fn(string $path): bool => !str_starts_with($path, 'governance/SubprocessDrain/'),
        ));

        self::assertNotSame(
            [],
            $outsideThisGroup,
            'The scan finds callers only inside this group, so it is not reaching the rest of the tree.',
        );

        self::assertNotContains(
            'governance/SubprocessDrain/ModuleIsLoadedByPathTest.php',
            $callers,
            'This file names the call only in its own documentation. Counting it means the comment exemption is '
            . 'gone, and every docblock mentioning the module has become a caller.',
        );
    }

    /**
     * The fold, measured on text this control writes. Without this nothing here
     * would refuse a return to a case-sensitive needle: every call in the tree
     * is written in the class's own casing, so the scan answers the same either
     * way and the whole group stays green while the gate is blind again.
     *
     * The Kelvin sign fixes the fold to `strtolower`. `mb_strtolower` rewrites
     * it from three bytes to one, so every offset after it is read back out of
     * the original two bytes early and the spelling asserted below comes back
     * shifted. It sits in a case that *reports* a spelling for that reason: an
     * earlier form put it in the docblock case instead, where the only
     * observable is a boolean, and a boolean only flips once the shift crosses
     * a token boundary — measured, that needed five signs, four were silent,
     * and one added space in the fixture would have silenced five. Asserting
     * the bytes needs no such arithmetic.
     *
     * Every spelling is assembled from halves: string literals in this file are
     * not excused, so writing one whole would make this control a caller of the
     * module it judges.
     */
    #[Test]
    public function itSeesTheCallWhateverCaseItIsWrittenIn(): void
    {
        $mixedCase = "<?php\n\$sign = '\u{212A}';\n" . '$r = child' . 'process::Run($command, $directory);' . "\n";
        $upperCase = '<?php' . "\n" . '$r = \CHILD' . 'PROCESS::RUN($command, $directory);' . "\n";
        $documented = '<?php' . "\n"
            . '/** Names the call only here: {@see Child' . 'Process::run()}. */' . "\n"
            . 'final class Documented {}' . "\n";

        self::assertSame(
            ['child' . 'process::Run('],
            self::callOccurrencesIn($mixedCase),
            'A call written in another case is not seen, or its bytes are read back wrong. PHP resolves class '
            . 'and method names without regard to case, so this is a working call, and a caller the scan misses '
            . 'is never asked for its `require_once` — which is the whole subject of this control. A spelling '
            . 'that comes back shifted instead means the fold stopped preserving byte length.',
        );
        self::assertSame(
            ['CHILD' . 'PROCESS::RUN('],
            self::callOccurrencesIn($upperCase),
            'The same in upper case, reached through a fully qualified name.',
        );
        self::assertSame(
            [],
            self::callOccurrencesIn($documented),
            'A file naming the call only in a docblock became a caller, so the comment exemption is gone and '
            . 'every docblock mentioning the module is now a caller.',
        );
    }

    #[Test]
    public function itRefusesACallerThatDoesNotRequireTheModuleByPath(): void
    {
        $refused = [];

        foreach (self::callers() as $path) {
            $contents = (string) file_get_contents(PhpFilePopulation::root() . '/' . $path);
            $directory = \dirname(PhpFilePopulation::root() . '/' . $path);
            $module = realpath(PhpFilePopulation::root() . '/' . self::MODULE_PATH);

            $missed = [];
            $loaded = false;

            foreach (self::requireOnceExpressions($contents) as $expression) {
                $resolved = self::resolve($expression, $directory);

                if ($resolved === $module) {
                    $loaded = true;

                    break;
                }

                if (str_contains($expression, 'ChildProcess.php')) {
                    $missed[] = $expression . ($resolved === null ? '' : ' → ' . $resolved);
                }
            }

            if ($loaded) {
                continue;
            }

            $refused[] = $missed === []
                ? $path
                : $path . ' (a require_once that names the module but does not reach it: '
                    . implode(', ', $missed) . ')';
        }

        self::assertSame(
            [],
            $refused,
            'A caller of ' . ChildProcess::class . ' that does not `require_once` ' . self::MODULE_PATH
            . ' by path. Composer\'s autoloader is not enough: an isolated scratch project symlinks `vendor/`, so '
            . 'an autoloaded class resolves back to this tree instead of to the copy under test. Add the '
            . '`require_once`, building the path from `__DIR__` or `dirname(__DIR__, N)`.',
        );
    }

    /**
     * Files that call the module, as paths relative to the repository root.
     *
     * @return list<string>
     */
    private static function callers(): array
    {
        $callers = [];

        foreach (PhpFilePopulation::paths() as $path) {
            if ($path === self::MODULE_PATH) {
                continue;
            }

            $contents = (string) file_get_contents(PhpFilePopulation::root() . '/' . $path);

            if (self::callsModuleIn($contents)) {
                $callers[] = $path;
            }
        }

        return $callers;
    }

    /**
     * The match itself, over one file's text, so that what the scan does can be
     * measured on text this control writes rather than only on the spellings
     * the tree happens to carry.
     */
    private static function callsModuleIn(string $contents): bool
    {
        return self::callOccurrencesIn($contents) !== [];
    }

    /**
     * Each call outside a comment, as the bytes the file actually carries.
     *
     * The spelling is read out of the original at the offset found in the
     * folded copy, which is what makes a fold that does not preserve byte
     * length observable: any shift at all reports the wrong bytes. The boolean
     * above cannot show that on its own — a shifted offset still lands on some
     * token, and whether the answer flips depends on how far the next token
     * boundary happens to be.
     *
     * @return list<string>
     */
    private static function callOccurrencesIn(string $contents): array
    {
        $folded = strtolower($contents);

        if (!str_contains($folded, self::CALL_NEEDLE)) {
            return [];
        }

        $tokens = PhpToken::tokenize($contents);
        $offset = 0;
        $found = [];

        while (($at = strpos($folded, self::CALL_NEEDLE, $offset)) !== false) {
            $offset = $at + \strlen(self::CALL_NEEDLE);
            $token = self::tokenAt($tokens, $at);

            if ($token !== null && $token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }

            $found[] = substr($contents, $at, \strlen(self::CALL_NEEDLE));
        }

        return $found;
    }

    /**
     * Each `require_once` expression in the file, as its tokens spell it with
     * whitespace and comments removed, so that `__DIR__ . '/x'` and
     * `__DIR__.'/x'` reach the resolver as one form.
     *
     * @return list<string>
     */
    private static function requireOnceExpressions(string $contents): array
    {
        $expressions = [];
        $current = null;

        foreach (PhpToken::tokenize($contents) as $token) {
            if ($token->is(\T_REQUIRE_ONCE)) {
                $current = '';

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($token->text === ';') {
                $expressions[] = $current;
                $current = null;

                continue;
            }

            if ($token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }

            $current .= $token->text;
        }

        return $expressions;
    }

    /**
     * The realpath the expression names, or null when this control cannot read
     * the form — which its caller treats as a refusal, never as a pass.
     */
    private static function resolve(string $expression, string $directory): ?string
    {
        $pattern = '~^(?:__DIR__|\\\\?dirname\(__DIR__(?:,(?<levels>\d+))?\))\.'
            . '(?<quote>[\'"])(?<suffix>[^\'"]*)(?P=quote)$~';

        if (preg_match($pattern, $expression, $matches) !== 1) {
            return null;
        }

        $base = $directory;

        if (str_contains($expression, 'dirname(')) {
            $levels = ($matches['levels'] ?? '') === '' ? 1 : (int) $matches['levels'];

            // `dirname($path, 0)` is a TypeError, and a caller that wrote it
            // would be refused by PHP before this control ever ran. Refusing it
            // here keeps the resolver total instead of throwing inside a
            // governance run.
            if ($levels < 1) {
                return null;
            }

            $base = \dirname($directory, $levels);
        }

        $path = realpath($base . $matches['suffix']);

        return $path === false ? null : $path;
    }

    /**
     * The token the byte at `$offset` belongs to. A token's own `line` is where
     * it starts, which for a nowdoc is several lines above the text inside it,
     * so the token is consulted only for what kind of thing the occurrence sits
     * in.
     *
     * @param array<PhpToken> $tokens
     */
    private static function tokenAt(array $tokens, int $offset): ?PhpToken
    {
        foreach ($tokens as $token) {
            if ($token->pos > $offset) {
                return null;
            }

            if ($offset < $token->pos + \strlen($token->text)) {
                return $token;
            }
        }

        return null;
    }
}
