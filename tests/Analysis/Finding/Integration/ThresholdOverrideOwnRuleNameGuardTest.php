<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The narrow sweep's whole invariant — that a rule can read only its own
 * `@qmx-threshold`, never a neighbour's — is not enforced by any type. It
 * holds only because every `AnalysisContext::getThresholdOverride()` call
 * site passes `$this->getName()` for the rule name, which
 * {@see \Qualimetrix\Analysis\Finding\RuleExecution::isEnabled()} narrows by
 * exact producer name (see `docs/adr/0040-narrow-directive-sweep.md`). A third
 * call site passing a literal, a foreign variable, or another rule's name
 * would let a counterfactual for rule A read rule B's directive while A alone
 * is executing — silently, since the narrow and full sweeps would then
 * disagree on findings neither `ThresholdDirectiveAudit::assertNarrowingChangedNothing()`
 * nor its `Full`-scope control sees (both compare against the *same* leak).
 *
 * This guard makes that assumption checkable instead of merely documented, and
 * it is deliberately **fail-closed**: two prior rounds of review found the
 * first version fail-open on forms it never recognized (`?->`, a comment
 * between the operator and the name, dynamic dispatch) and on a second reader
 * of the same map — `AnalysisContext::$thresholdOverrides` is a public
 * property, so a rule could read it directly without ever calling the method
 * this guard was watching. Both doors are closed the same way: anything this
 * guard cannot positively recognize as safe is a violation, not a skip.
 *
 * - **Method calls.** Every occurrence of the identifier `getThresholdOverride`
 *   is found by token type, which already excludes docblocks and comments (the
 *   tokenizer folds them into one comment token) and string literals (a
 *   different token type) — matching the precedent in
 *   {@see RuleIdentifierLiteralGuardTest} that a regex cannot tell a real
 *   reference from the same text elsewhere. The one declaration
 *   (`function getThresholdOverride(...)` in `AnalysisContext.php`) is
 *   recognised and skipped. Every other occurrence must be a plain `->` or
 *   `?->` call — comments between the operator and the name are skipped, not
 *   treated as breaking the pattern — with a first argument that tokenizes to
 *   exactly `$this->getName()`; anything else (a foreign name, a dynamic
 *   `->{$name}(...)`, a static reference, a first-class callable) is a
 *   violation. A string literal spelling the method's name anywhere in `src/`
 *   is a violation outright: `call_user_func([$x, 'getThresholdOverride'], ...)`
 *   and `$x->{'getThresholdOverride'}(...)` both carry one, and this guard
 *   cannot verify what a dynamic dispatch passes as its first argument, so it
 *   refuses the form instead of trying.
 * - **Property reads.** A variable whose parameter type-hint reads
 *   `AnalysisContext $name` in the same file is tracked, and a bare
 *   `->thresholdOverrides` (or `?->thresholdOverrides`, not followed by `(`)
 *   on it outside `AnalysisContext.php` itself is a violation. The tracking is
 *   textual, not a type solver: it follows this project's one call convention
 *   (`analyze(AnalysisContext $context)`) rather than aliasing through
 *   reassignment, which is enough to catch a rule reading the map directly
 *   without inventing a second type checker.
 */
final class ThresholdOverrideOwnRuleNameGuardTest extends TestCase
{
    private const string METHOD = 'getThresholdOverride';
    private const string PROPERTY = 'thresholdOverrides';
    private const string OWNING_TYPE_RELATIVE_PATH = 'src/Analysis/Finding/Contract/Rule/AnalysisContext.php';

    #[Test]
    public function everyCallSitePassesItsOwnRuleNameAsTheFirstArgument(): void
    {
        $root = self::projectRoot();
        $violations = [];

        foreach (self::productionPhpFiles($root) as $absolutePath) {
            $relative = substr($absolutePath, \strlen($root) + 1);
            $source = self::readSource($absolutePath);
            $isOwningFile = $relative === self::OWNING_TYPE_RELATIVE_PATH;

            foreach (self::violations($source, $isOwningFile) as [$line, $message]) {
                $violations[] = \sprintf('%s:%d %s', $relative, $line, $message);
            }
        }

        self::assertSame([], $violations, "\n" . implode("\n", $violations));
    }

    /**
     * A control proving the guard actually reads call sites: the two known
     * production callers ({@see \Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule::getEffectiveOptions()}
     * and {@see \Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListRule::analyze()})
     * must both be found. A guard finding zero call sites would pass
     * {@see everyCallSitePassesItsOwnRuleNameAsTheFirstArgument()} by having
     * nothing to check.
     */
    #[Test]
    public function itFindsAtLeastTheTwoKnownProductionCallSites(): void
    {
        $root = self::projectRoot();
        $found = 0;

        foreach (self::productionPhpFiles($root) as $absolutePath) {
            $found += \count(self::methodCallSites(token_get_all(self::readSource($absolutePath))));
        }

        self::assertGreaterThanOrEqual(2, $found);
    }

    /**
     * The correct, canonical call is not itself a violation. Without this, a
     * defect that flags every call site — including the two real ones — would
     * still turn {@see everyCallSitePassesItsOwnRuleNameAsTheFirstArgument()}
     * red for the wrong reason and could be "fixed" by weakening the guard
     * instead of the call site.
     */
    #[Test]
    public function itAcceptsThePlainCallWithItsOwnRuleName(): void
    {
        $source = '<?php $override = $context->getThresholdOverride($this->getName(), $subject);';

        self::assertSame([], self::violations($source, isOwningFile: false));
    }

    /**
     * Every form a prior review round proved this guard's first version let
     * through: a nullsafe call, a comment splitting the operator from the
     * method name, dynamic dispatch through a brace expression, dynamic
     * dispatch through `call_user_func`, and a first-class callable. Each
     * snippet carries a first argument that is *not* `$this->getName()` (a
     * foreign literal or none at all), so a guard that recognised the form but
     * validated the argument correctly would still catch it — the point of
     * this test is specifically that the form itself is never silently
     * skipped.
     *
     * @param non-empty-string $source
     */
    #[Test]
    #[DataProvider('provideFormsThatMustNotBeInvisible')]
    public function itTreatsEveryUnrecognizedOrForeignCallFormAsAViolation(string $source): void
    {
        self::assertNotSame([], self::violations($source, isOwningFile: false));
    }

    /**
     * @return iterable<string, array{0: non-empty-string}>
     */
    public static function provideFormsThatMustNotBeInvisible(): iterable
    {
        yield 'nullsafe operator' => [
            '<?php $override = $context?->getThresholdOverride("SomeOtherRule", $subject);',
        ];
        yield 'comment between operator and name' => [
            '<?php $override = $context->/* not a typo */getThresholdOverride("SomeOtherRule", $subject);',
        ];
        yield 'doc-comment between operator and name' => [
            '<?php $override = $context->/** @see nothing */getThresholdOverride("SomeOtherRule", $subject);',
        ];
        yield 'brace dispatch with a string literal' => [
            '<?php $override = $context->{\'getThresholdOverride\'}("SomeOtherRule", $subject);',
        ];
        yield 'call_user_func with a string literal' => [
            '<?php $override = call_user_func([$context, "getThresholdOverride"], "SomeOtherRule", $subject);',
        ];
        yield 'first-class callable' => [
            '<?php $reader = $context->getThresholdOverride(...);',
        ];
    }

    /**
     * The second door: `AnalysisContext::$thresholdOverrides` is a public
     * property, so a rule can read the whole override map without ever
     * calling the method this guard otherwise polices. A variable typed
     * `AnalysisContext $context` reading `->thresholdOverrides` directly is a
     * violation regardless of what the method-call check finds — the two
     * checks are independent because the two leaks are independent.
     */
    #[Test]
    public function itTreatsADirectPropertyReadAsAViolation(): void
    {
        $source = <<<'PHP'
            <?php
            final class RogueRule
            {
                public function analyze(AnalysisContext $context): array
                {
                    $overrides = $context->thresholdOverrides;

                    return [];
                }
            }
            PHP;

        self::assertNotSame([], self::violations($source, isOwningFile: false));
    }

    /**
     * The nullsafe form of the same leak.
     */
    #[Test]
    public function itTreatsANullsafeDirectPropertyReadAsAViolation(): void
    {
        $source = '<?php function f(AnalysisContext $context) { return $context?->thresholdOverrides; }';

        self::assertNotSame([], self::violations($source, isOwningFile: false));
    }

    /**
     * The one legitimate reader of the property is the type that owns it. The
     * property check must not fire inside `AnalysisContext.php` itself, or the
     * guard would forbid the accessor method from reading its own state.
     */
    #[Test]
    public function itAllowsTheOwningTypeToReadItsOwnProperty(): void
    {
        $source = <<<'PHP'
            <?php
            final class AnalysisContext
            {
                public function __construct(public array $thresholdOverrides = []) {}

                public function getThresholdOverride(): void
                {
                    foreach ($this->thresholdOverrides as $overrides) {
                    }
                }
            }
            PHP;

        self::assertSame([], self::violations($source, isOwningFile: true));
    }

    /**
     * Every violation both checks can produce, combined. Kept as one entry
     * point so the production sweep and the fixture-based tests exercise
     * exactly the same code path.
     *
     * @return list<array{0: int, 1: string}> [line, message] pairs
     */
    private static function violations(string $source, bool $isOwningFile): array
    {
        $tokens = token_get_all($source);

        return [
            ...self::methodCallViolations($tokens),
            ...self::propertyReadViolations($tokens, $isOwningFile),
        ];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function methodCallViolations(array $tokens): array
    {
        $violations = [];

        foreach ($tokens as $index => $token) {
            if (
                \is_array($token)
                && $token[0] === \T_CONSTANT_ENCAPSED_STRING
                && self::stringLiteralNames($token[1], self::METHOD)
            ) {
                $violations[] = [$token[2], \sprintf(
                    'names %s as a string literal; dynamic dispatch is not a checkable call form and is refused'
                    . ' outright.',
                    self::METHOD,
                )];

                continue;
            }

            if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] !== self::METHOD) {
                continue;
            }

            $previous = self::previousMeaningfulToken($tokens, $index);

            if (\is_array($previous) && $previous[0] === \T_FUNCTION) {
                // The one production declaration. A declaration of this name
                // anywhere else in the tree is not a call this guard polices.
                continue;
            }

            $isObjectAccess = \is_array($previous)
                && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true);

            $openParenIndex = self::nextMeaningfulTokenIndex($tokens, $index);
            $hasParens = $openParenIndex !== null && $tokens[$openParenIndex] === '(';

            if (!$isObjectAccess || !$hasParens) {
                $violations[] = [$token[2], \sprintf(
                    '%1$s appears in a form this guard does not recognize as a checkable call'
                    . ' (expected "->%1$s(...)" or "?->%1$s(...)"); an unrecognized form is a violation, not a'
                    . ' skip.',
                    self::METHOD,
                )];

                continue;
            }

            $arguments = self::firstArgumentTokens($tokens, $openParenIndex);

            if (!self::firstArgumentIsOwnRuleName($arguments)) {
                $violations[] = [$token[2], \sprintf(
                    'calls %s() with a first argument other than $this->getName().',
                    self::METHOD,
                )];
            }
        }

        return $violations;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<int> token indexes
     */
    private static function methodCallSites(array $tokens): array
    {
        $sites = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] !== self::METHOD) {
                continue;
            }

            $previous = self::previousMeaningfulToken($tokens, $index);

            if (\is_array($previous) && $previous[0] === \T_FUNCTION) {
                continue;
            }

            $isObjectAccess = \is_array($previous)
                && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true);
            $openParenIndex = self::nextMeaningfulTokenIndex($tokens, $index);

            if ($isObjectAccess && $openParenIndex !== null && $tokens[$openParenIndex] === '(') {
                $sites[] = $index;
            }
        }

        return $sites;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function propertyReadViolations(array $tokens, bool $isOwningFile): array
    {
        if ($isOwningFile) {
            return [];
        }

        $contextTypedVariables = self::analysisContextTypedVariables($tokens);

        if ($contextTypedVariables === []) {
            return [];
        }

        $violations = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_VARIABLE || !\in_array($token[1], $contextTypedVariables, true)) {
                continue;
            }

            $operatorIndex = self::nextMeaningfulTokenIndex($tokens, $index);
            $operator = $operatorIndex !== null ? $tokens[$operatorIndex] : null;

            if (!\is_array($operator) || !\in_array($operator[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $propertyIndex = self::nextMeaningfulTokenIndex($tokens, $operatorIndex);
            $property = $propertyIndex !== null ? $tokens[$propertyIndex] : null;

            if (!\is_array($property) || $property[0] !== \T_STRING || $property[1] !== self::PROPERTY) {
                continue;
            }

            $afterPropertyIndex = self::nextMeaningfulTokenIndex($tokens, $propertyIndex);

            if ($afterPropertyIndex !== null && $tokens[$afterPropertyIndex] === '(') {
                continue; // a method call, not a property read; not this check's concern
            }

            $violations[] = [$property[2], \sprintf(
                'reads AnalysisContext::$%s directly instead of calling ->%s(); the invariant this guard'
                . ' enforces has no meaning if a rule can read the override map straight from the public'
                . ' property.',
                self::PROPERTY,
                self::METHOD,
            )];
        }

        return $violations;
    }

    /**
     * Every variable whose parameter type-hint in this file reads
     * `AnalysisContext $name`. Textual and file-scoped, matching this
     * project's one calling convention for the type rather than solving
     * aliasing or cross-file flow.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string>
     */
    private static function analysisContextTypedVariables(array $tokens): array
    {
        $variables = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_VARIABLE) {
                continue;
            }

            $previous = self::previousMeaningfulToken($tokens, $index);
            $previousText = \is_array($previous) ? $previous[1] : $previous;

            if ($previousText === 'AnalysisContext') {
                $variables[$token[1]] = true;
            }
        }

        return array_keys($variables);
    }

    /**
     * Whether a `T_CONSTANT_ENCAPSED_STRING` token's raw text (quotes
     * included) spells exactly `$name`. Callers narrow the token type before
     * calling this, which keeps the check itself free of the union-typed
     * token shape. Excludes docblocks and ordinary comments by construction:
     * `token_get_all()` folds a whole `/** ... *\/` or `// ...` into one
     * comment token, so the identifier never surfaces as a string literal
     * token from inside one.
     */
    private static function stringLiteralNames(string $rawTokenText, string $name): bool
    {
        return substr($rawTokenText, 1, -1) === $name;
    }

    /**
     * Whether a first argument's tokens spell exactly `$this->getName()`,
     * whitespace and comments aside.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argumentTokens raw tokens, `token_get_all` shape
     */
    private static function firstArgumentIsOwnRuleName(array $argumentTokens): bool
    {
        $meaningful = [];

        foreach ($argumentTokens as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $meaningful[] = \is_array($token) ? $token[1] : $token;
        }

        return $meaningful === ['$this', '->', 'getName', '(', ')'];
    }

    /**
     * The tokens of the call's first argument: everything between the opening
     * `(` and the first top-level `,` or the matching closing `)`, respecting
     * nested parentheses so a first argument that is itself a call is not cut
     * short.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function firstArgumentTokens(array $tokens, int $openParenIndex): array
    {
        $depth = 0;
        $argument = [];

        for ($i = $openParenIndex; $i < \count($tokens); ++$i) {
            $token = $tokens[$i];
            $text = \is_array($token) ? $token[1] : $token;

            if ($text === '(') {
                ++$depth;

                if ($depth === 1) {
                    continue;
                }
            }

            if ($text === ')') {
                --$depth;

                if ($depth === 0) {
                    break;
                }
            }

            if ($depth === 1 && $text === ',') {
                break;
            }

            if ($depth >= 1 && $i !== $openParenIndex) {
                $argument[] = $token;
            }
        }

        return $argument;
    }

    /**
     * The nearest meaningful token before `$index`: whitespace and comments
     * (both `//`/`#` line comments and `/** *\/` doc-comments) are transparent
     * to the patterns this guard matches, so a comment inserted between an
     * operator and a name must not hide the call from it.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function previousMeaningfulToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            $token = $tokens[$i];

            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextMeaningfulTokenIndex(array $tokens, int $index): ?int
    {
        for ($i = $index + 1; $i < \count($tokens); ++$i) {
            $token = $tokens[$i];

            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private static function readSource(string $absolutePath): string
    {
        $source = file_get_contents($absolutePath);

        if ($source === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $absolutePath));
        }

        return $source;
    }

    /**
     * @return list<string> absolute paths
     */
    private static function productionPhpFiles(string $root): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
