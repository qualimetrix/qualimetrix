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
 * The narrow sweep's whole invariant is narrower than "nothing reads the
 * override map" — it is that **a rule cannot read another rule's
 * `@qmx-threshold`**. The map itself has legitimate readers outside the rule
 * layer: {@see \Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext}
 * owns it, and {@see \Qualimetrix\Analysis\Policy\Inline\Directive\Audit\ThresholdDirectiveAudit},
 * the directive subject's own audit, reads and rewrites it directly to build
 * each counterfactual run — that is its job, not a leak. Both are named,
 * narrow exceptions below ({@see LEGITIMATE_DIRECT_READERS}), and both are
 * *exercised*: each one is a violation the moment its name is removed from the
 * list ({@see itFlagsTheAuditsChainedReadWhenItIsNotExcused()},
 * {@see itFlagsTheOwningTypesOwnReadWhenItIsNotExcused()}).
 *
 * Inside the rule layer, the invariant holds only because every
 * `AnalysisContext::getThresholdOverride()` call site passes `$this->getName()`
 * for the rule name, which
 * {@see \Qualimetrix\Analysis\Finding\RuleExecution::isEnabled()} narrows by
 * exact producer name (see `docs/adr/0040-narrow-directive-sweep.md`). A third
 * call site passing a literal, a foreign variable, or another rule's name
 * would let a counterfactual for rule A read rule B's directive while A alone
 * is executing — silently, since the narrow and full sweeps would then
 * disagree on findings neither `ThresholdDirectiveAudit::assertNarrowingChangedNothing()`
 * nor its `Full`-scope control sees (both compare against the *same* leak).
 *
 * This guard makes that assumption checkable instead of merely documented. It
 * judges **what it recognizes as the context**, and only that: a call or a
 * read is this guard's business when its receiver is an `AnalysisContext` —
 * either a variable type-hinted `AnalysisContext` in the same file, `$this`
 * inside the owning type, or a chain through a public field declared
 * `AnalysisContext` anywhere in `src/`
 * ({@see contextBearingFieldNames()}; today `PreparedRun::$context` and
 * `ThresholdDirectiveAuditInput::$baseline`). A same-named method on a foreign
 * type is not a violation — extracting threshold logic into a collaborator
 * that takes the rule name as a parameter is an ordinary move in this
 * repository, and the earlier version of this guard reddened on it while
 * nothing was leaking.
 *
 * Within that perimeter the guard stays **fail-closed**: two prior rounds of
 * review found the first version fail-open on forms it never recognized
 * (`?->`, a comment between the operator and the name, dynamic dispatch) and
 * on a second reader of the same map — `AnalysisContext::$thresholdOverrides`
 * is a public property, so a rule could read it directly without ever calling
 * the method this guard was watching. Anything on a recognized context that
 * this guard cannot positively read as safe is a violation, not a skip.
 *
 * - **Method calls.** Every occurrence of the identifier `getThresholdOverride`
 *   is found by token type, which already excludes docblocks and comments (the
 *   tokenizer folds them into one comment token) and string literals (a
 *   different token type) — matching the precedent in
 *   {@see RuleIdentifierLiteralGuardTest} that a regex cannot tell a real
 *   reference from the same text elsewhere. The one declaration
 *   (`function getThresholdOverride(...)` in `AnalysisContext.php`) is
 *   recognised and skipped. Every occurrence *on a recognized context* must be
 *   a plain `->` or `?->` call — comments between the operator and the name are
 *   skipped, not treated as breaking the pattern — with a first argument that
 *   tokenizes to exactly `$this->getName()`; anything else (a foreign name, a
 *   static reference, a first-class callable) is a violation. A string literal
 *   spelling the method's name anywhere in `src/` is a violation outright and
 *   regardless of receiver: `call_user_func([$x, 'getThresholdOverride'], ...)`
 *   and `$x->{'getThresholdOverride'}(...)` both carry one, this guard cannot
 *   verify what a dynamic dispatch passes as its first argument, and nothing
 *   in `src/` needs the method's name as data.
 * - **Property reads.** A bare `->thresholdOverrides` (or
 *   `?->thresholdOverrides`, not followed by `(`) on a recognized context,
 *   outside {@see LEGITIMATE_DIRECT_READERS}, is a violation. Receiver
 *   recognition is what keeps the check honest in both directions: five other
 *   types in `src/` carry a field of the same name (`AnalysisResult`,
 *   `CollectionPhaseOutput`, `SourceControls`, `SuccessfulFileProcessing`,
 *   `InlineDirectivePolicy`), and reading *those* is not this invariant's
 *   business.
 *
 * **What this guard is blind to**, stated rather than discovered later:
 *
 * - a method name assembled at runtime (concatenation, a variable holding
 *   `'getThreshold' . 'Override'`) — no identifier token ever carries it;
 * - a context reached through a carrier this guard cannot read textually: a
 *   local variable assigned from a factory call, a value returned by a method,
 *   a field typed through an import alias or a different short name. The
 *   field-carrier index closes the two carriers that exist today, not the
 *   general case;
 * - conversely, a foreign type whose public field happens to be named
 *   `context` or `baseline` is read as a context and can produce a false
 *   violation. That direction is the safe one, and the file's name in
 *   {@see LEGITIMATE_DIRECT_READERS} is where such a case would be answered.
 *
 * Closing the general case needs a different contract (overrides delivered to
 * a rule already filtered by its own name), not a better tokenizer;
 * `docs/internal/plans/rule-vocabulary/FOLLOWUPS.md`, "Х3-D", carries the three
 * measurements that ruled out holding this invariant with a type instead.
 */
final class ThresholdOverrideOwnRuleNameGuardTest extends TestCase
{
    private const string METHOD = 'getThresholdOverride';
    private const string PROPERTY = 'thresholdOverrides';
    private const string CONTEXT_TYPE = 'AnalysisContext';
    private const string OWNING_TYPE_RELATIVE_PATH = 'src/Analysis/Finding/Contract/Rule/AnalysisContext.php';
    private const string AUDIT_RELATIVE_PATH = 'src/Analysis/Policy/Inline/Directive/Audit/ThresholdDirectiveAudit.php';

    /**
     * Every production call site of `AnalysisContext::getThresholdOverride()`,
     * by file and by the method that makes the call. The sweep must find
     * exactly these: a canonical call that disappears is as much a change to
     * the invariant's perimeter as a third one that appears, and a count could
     * not tell the two apart.
     *
     * @var array<string, list<string>> relative path => enclosing method names
     */
    private const array KNOWN_CALL_SITES = [
        'src/Analysis/Evidence/CodeSmell/LongParameterListRule.php' => ['checkVoConstructor'],
        'src/Analysis/Finding/Contract/Rule/AbstractRule.php' => ['getEffectiveOptions'],
    ];

    /**
     * Files allowed to read `AnalysisContext::$thresholdOverrides` directly,
     * outside the rule layer this guard polices. A file earns a place here by
     * being named, with the reason it is not a rule reading a neighbour's
     * directive — and each entry is a violation without it, which is what
     * keeps this list an enforced exception rather than decoration.
     *
     * @var array<string, string> relative path => why the direct read is legitimate
     */
    private const array LEGITIMATE_DIRECT_READERS = [
        self::OWNING_TYPE_RELATIVE_PATH => 'the owning type, whose accessor reads its own state',
        self::AUDIT_RELATIVE_PATH => 'the directive subject\'s own audit: it reads and rewrites the map through'
            . ' ThresholdDirectiveAuditInput::$baseline to build each counterfactual run — the one thing the'
            . ' feature exists to do',
    ];

    #[Test]
    public function everyCallSiteOnAContextPassesItsOwnRuleNameAsTheFirstArgument(): void
    {
        $root = self::projectRoot();
        $fields = self::contextBearingFieldNames($root);
        $violations = [];

        foreach (self::productionPhpFiles($root) as $absolutePath) {
            $relative = self::relativePath($root, $absolutePath);
            $source = self::readSource($absolutePath);

            foreach (self::violations($source, self::isLegitimateDirectReader($relative), $fields) as [$line, $message]) {
                $violations[] = \sprintf('%s:%d %s', $relative, $line, $message);
            }
        }

        self::assertSame([], $violations, "\n" . implode("\n", $violations));
    }

    /**
     * The control that keeps the sweep attached to the places it names: the
     * two known callers must be found, in those files, in those methods, and
     * nothing else may call the accessor. A guard finding zero call sites
     * would pass {@see everyCallSiteOnAContextPassesItsOwnRuleNameAsTheFirstArgument()}
     * by having nothing to check; a guard finding two *different* ones would
     * pass a count.
     */
    #[Test]
    public function itFindsExactlyTheKnownProductionCallSites(): void
    {
        self::assertSame(self::KNOWN_CALL_SITES, self::productionCallSiteIndex(self::projectRoot()));
    }

    /**
     * The two field carriers this guard resolves a chained context through.
     * Named here because the property check's whole reach depends on them: a
     * carrier that stops being found silently shrinks the perimeter, which is
     * exactly how the previous version's exception list became inert.
     */
    #[Test]
    public function itFindsThePublicFieldsThatCarryAContext(): void
    {
        self::assertSame(['baseline', 'context'], self::contextBearingFieldNames(self::projectRoot()));
    }

    /**
     * {@see LEGITIMATE_DIRECT_READERS} is not decoration: the audit's real
     * source, swept without its exception, must be a violation. This is the
     * live half of the exception — the previous version listed the same file
     * while its property check could not see the access at all, so the entry
     * changed no outcome either way.
     */
    #[Test]
    public function itFlagsTheAuditsChainedReadWhenItIsNotExcused(): void
    {
        $root = self::projectRoot();
        $source = self::readSource($root . '/' . self::AUDIT_RELATIVE_PATH);

        self::assertNotSame(
            [],
            self::violations($source, isLegitimateDirectReader: false, contextBearingFields: self::contextBearingFieldNames($root)),
        );
    }

    /**
     * The same, for the other named exception: the owning type reads its own
     * property, and would be a violation without its entry.
     */
    #[Test]
    public function itFlagsTheOwningTypesOwnReadWhenItIsNotExcused(): void
    {
        $root = self::projectRoot();
        $source = self::readSource($root . '/' . self::OWNING_TYPE_RELATIVE_PATH);

        self::assertNotSame(
            [],
            self::violations($source, isLegitimateDirectReader: false, contextBearingFields: self::contextBearingFieldNames($root)),
        );
    }

    /**
     * The correct, canonical call is not itself a violation. Without this, a
     * defect that flags every call site — including the two real ones — would
     * still turn {@see everyCallSiteOnAContextPassesItsOwnRuleNameAsTheFirstArgument()}
     * red for the wrong reason and could be "fixed" by weakening the guard
     * instead of the call site.
     */
    #[Test]
    public function itAcceptsThePlainCallWithItsOwnRuleName(): void
    {
        $source = <<<'PHP'
            <?php
            final class HonestRule
            {
                public function analyze(AnalysisContext $context): array
                {
                    $override = $context->getThresholdOverride($this->getName(), $subject);

                    return [];
                }
            }
            PHP;

        self::assertSame([], self::violations($source, isLegitimateDirectReader: false, contextBearingFields: []));
    }

    /**
     * The same shape with a foreign rule name. Paired with
     * {@see itAcceptsThePlainCallWithItsOwnRuleName()} it shows the green
     * above is the argument's doing and not the guard failing to see the call.
     */
    #[Test]
    public function itTreatsAForeignRuleNameOnAContextAsAViolation(): void
    {
        $source = <<<'PHP'
            <?php
            final class RogueRule
            {
                public function analyze(AnalysisContext $context): array
                {
                    $override = $context->getThresholdOverride('some.other.rule', $subject);

                    return [];
                }
            }
            PHP;

        self::assertNotSame([], self::violations($source, isLegitimateDirectReader: false, contextBearingFields: []));
    }

    /**
     * The false positive this guard used to carry: a collaborator that takes a
     * rule name as a parameter and asks *its own* dependency for an override
     * violates nothing — it never touches an `AnalysisContext`. The previous
     * version reddened on it, and extracting threshold logic into such a
     * collaborator is an ordinary move here, so the red pushed the next author
     * towards weakening the guard.
     */
    #[Test]
    public function itAcceptsASameNamedMethodOnAForeignType(): void
    {
        $source = <<<'PHP'
            <?php
            final class ThresholdHintProvider
            {
                public function __construct(private ThresholdRegistry $registry) {}

                public function hintFor(string $ruleName, MetricSubject $subject): ?ThresholdOverride
                {
                    return $this->registry->getThresholdOverride($ruleName, $subject);
                }
            }
            PHP;

        self::assertSame([], self::violations($source, isLegitimateDirectReader: false, contextBearingFields: []));
    }

    /**
     * Every form a prior review round proved this guard's first version let
     * through: a nullsafe call, a comment splitting the operator from the
     * method name, dynamic dispatch through a brace expression, dynamic
     * dispatch through `call_user_func`, a first-class callable, a static
     * reference, and a call on a context reached through a field carrier. Each
     * snippet carries a first argument that is *not* `$this->getName()` (a
     * foreign literal or none at all), so a guard that recognised the form but
     * validated the argument correctly would still catch it — the point of
     * this test is specifically that the form itself is never silently
     * skipped.
     *
     * @param non-empty-string $source
     * @param list<string> $contextBearingFields
     */
    #[Test]
    #[DataProvider('provideFormsThatMustNotBeInvisible')]
    public function itTreatsEveryUnrecognizedOrForeignCallFormAsAViolation(string $source, array $contextBearingFields = []): void
    {
        self::assertNotSame([], self::violations($source, isLegitimateDirectReader: false, contextBearingFields: $contextBearingFields));
    }

    /**
     * @return iterable<string, array{0: non-empty-string, 1?: list<string>}>
     */
    public static function provideFormsThatMustNotBeInvisible(): iterable
    {
        yield 'nullsafe operator' => [
            '<?php function f(AnalysisContext $context) { return $context?->getThresholdOverride("Other", $s); }',
        ];
        yield 'comment between operator and name' => [
            '<?php function f(AnalysisContext $context) { return $context->/* no */getThresholdOverride("Other", $s); }',
        ];
        yield 'doc-comment between operator and name' => [
            '<?php function f(AnalysisContext $context) { return $context->/** @see x */getThresholdOverride("Other", $s); }',
        ];
        yield 'brace dispatch with a string literal' => [
            '<?php function f(AnalysisContext $context) { return $context->{\'getThresholdOverride\'}("Other", $s); }',
        ];
        yield 'call_user_func with a string literal' => [
            '<?php function f(AnalysisContext $context) { return call_user_func([$context, "getThresholdOverride"], "Other", $s); }',
        ];
        yield 'first-class callable' => [
            '<?php function f(AnalysisContext $context) { $reader = $context->getThresholdOverride(...); }',
        ];
        yield 'static reference on a context variable' => [
            '<?php function f(AnalysisContext $context) { return $context::getThresholdOverride("Other", $s); }',
        ];
        yield 'call through a field carrier' => [
            '<?php final class R { public function f(Input $in) { return $in->baseline->getThresholdOverride("Other", $s); } }',
            ['baseline'],
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

        self::assertNotSame([], self::violations($source, isLegitimateDirectReader: false, contextBearingFields: []));
    }

    /**
     * The nullsafe form of the same leak.
     */
    #[Test]
    public function itTreatsANullsafeDirectPropertyReadAsAViolation(): void
    {
        $source = '<?php function f(AnalysisContext $context) { return $context?->thresholdOverrides; }';

        self::assertNotSame([], self::violations($source, isLegitimateDirectReader: false, contextBearingFields: []));
    }

    /**
     * The read the previous version could not see at all: the context arrives
     * through another type's public field, so no parameter in this file is
     * type-hinted `AnalysisContext`. This is the shape the audit itself uses,
     * and the shape a rule holding a `PreparedRun` would use.
     */
    #[Test]
    public function itTreatsAPropertyReadThroughAFieldCarrierAsAViolation(): void
    {
        $source = <<<'PHP'
            <?php
            final class RogueReader
            {
                public function peek(ThresholdDirectiveAuditInput $input): array
                {
                    return $input->baseline->thresholdOverrides;
                }
            }
            PHP;

        self::assertNotSame([], self::violations($source, isLegitimateDirectReader: false, contextBearingFields: ['baseline']));
    }

    /**
     * The same read, from a file the list names. Without this,
     * {@see LEGITIMATE_DIRECT_READERS} could grow without ever changing an
     * outcome.
     */
    #[Test]
    public function itAllowsADeclaredLegitimateReaderToReadThroughAFieldCarrier(): void
    {
        $source = <<<'PHP'
            <?php
            final class ThresholdDirectiveAudit
            {
                public function verdicts(ThresholdDirectiveAuditInput $input): array
                {
                    return $input->baseline->thresholdOverrides;
                }
            }
            PHP;

        self::assertSame([], self::violations($source, isLegitimateDirectReader: true, contextBearingFields: ['baseline']));
    }

    /**
     * A field of the same name on a type that is not a context — five such
     * fields exist in `src/` — is not this invariant's business, and the
     * production sweep would drown in them if it were.
     */
    #[Test]
    public function itIgnoresASameNamedFieldOnAForeignType(): void
    {
        $source = <<<'PHP'
            <?php
            final class Merge
            {
                public function of(AnalysisResult $other): array
                {
                    return $other->thresholdOverrides;
                }
            }
            PHP;

        self::assertSame([], self::violations($source, isLegitimateDirectReader: false, contextBearingFields: ['baseline', 'context']));
    }

    /**
     * The owning type reads `$this->thresholdOverrides` in its own accessor.
     * `$this` counts as a context inside the file that declares the type —
     * otherwise the owning type's entry in {@see LEGITIMATE_DIRECT_READERS}
     * would excuse a read nothing was flagging.
     */
    #[Test]
    public function itAllowsTheOwningTypeToReadItsOwnProperty(): void
    {
        self::assertSame([], self::violations(self::owningTypeShapedSource(), isLegitimateDirectReader: true, contextBearingFields: []));
    }

    #[Test]
    public function itFlagsTheOwningTypesShapeWithoutTheException(): void
    {
        self::assertNotSame([], self::violations(self::owningTypeShapedSource(), isLegitimateDirectReader: false, contextBearingFields: []));
    }

    private static function owningTypeShapedSource(): string
    {
        return <<<'PHP'
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
    }

    /**
     * Every violation both checks can produce, combined. Kept as one entry
     * point so the production sweep and the fixture-based tests exercise
     * exactly the same code path.
     *
     * @param list<string> $contextBearingFields public field names declared `AnalysisContext` tree-wide
     *
     * @return list<array{0: int, 1: string}> [line, message] pairs
     */
    private static function violations(string $source, bool $isLegitimateDirectReader, array $contextBearingFields): array
    {
        $tokens = token_get_all($source);
        $variables = self::contextTypedVariables($tokens);

        return [
            ...self::methodCallViolations($tokens, $variables, $contextBearingFields),
            ...self::propertyReadViolations($tokens, $variables, $contextBearingFields, $isLegitimateDirectReader),
        ];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string> $contextVariables
     * @param list<string> $contextBearingFields
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function methodCallViolations(array $tokens, array $contextVariables, array $contextBearingFields): array
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

            if (!self::receiverIsContext($tokens, $index, $contextVariables, $contextBearingFields)) {
                // A same-named method on some other type. Not this invariant.
                continue;
            }

            $isObjectAccess = \is_array($previous)
                && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true);

            $openParenIndex = self::nextMeaningfulTokenIndex($tokens, $index);
            $hasParens = $openParenIndex !== null && $tokens[$openParenIndex] === '(';

            if (!$isObjectAccess || !$hasParens) {
                $violations[] = [$token[2], \sprintf(
                    '%1$s appears on an AnalysisContext in a form this guard does not recognize as a checkable'
                    . ' call (expected "->%1$s(...)" or "?->%1$s(...)"); an unrecognized form is a violation, not'
                    . ' a skip.',
                    self::METHOD,
                )];

                continue;
            }

            if (!self::firstArgumentIsOwnRuleName(self::firstArgumentTokens($tokens, $openParenIndex))) {
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
     * @param list<string> $contextVariables
     * @param list<string> $contextBearingFields
     *
     * @return list<int> token indexes of `->getThresholdOverride(` on a recognized context
     */
    private static function methodCallSites(array $tokens, array $contextVariables, array $contextBearingFields): array
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

            if (!self::receiverIsContext($tokens, $index, $contextVariables, $contextBearingFields)) {
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
     * @param list<string> $contextVariables
     * @param list<string> $contextBearingFields
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function propertyReadViolations(
        array $tokens,
        array $contextVariables,
        array $contextBearingFields,
        bool $isLegitimateDirectReader,
    ): array {
        if ($isLegitimateDirectReader) {
            return [];
        }

        $violations = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] !== self::PROPERTY) {
                continue;
            }

            $operator = self::previousMeaningfulToken($tokens, $index);

            if (
                !\is_array($operator)
                || !\in_array($operator[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)
            ) {
                continue; // a named argument, a declaration, an array key — not a read
            }

            $afterPropertyIndex = self::nextMeaningfulTokenIndex($tokens, $index);

            if ($afterPropertyIndex !== null && $tokens[$afterPropertyIndex] === '(') {
                continue; // a method call, not a property read; not this check's concern
            }

            if (!self::receiverIsContext($tokens, $index, $contextVariables, $contextBearingFields)) {
                continue; // one of the five same-named fields on other types
            }

            $violations[] = [$token[2], \sprintf(
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
     * Whether the receiver of the member access at `$index` is something this
     * guard reads as an `AnalysisContext`: a variable type-hinted in this file
     * (`$this` included inside the owning type), or a chain through a public
     * field declared `AnalysisContext` somewhere in `src/`. Everything else is
     * a foreign type, and out of this invariant's perimeter.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string> $contextVariables
     * @param list<string> $contextBearingFields
     */
    private static function receiverIsContext(
        array $tokens,
        int $index,
        array $contextVariables,
        array $contextBearingFields,
    ): bool {
        $operatorIndex = self::previousMeaningfulTokenIndex($tokens, $index);

        if ($operatorIndex === null) {
            return false;
        }

        $operator = $tokens[$operatorIndex];
        $isMemberAccess = \is_array($operator)
            && \in_array(
                $operator[0],
                [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON],
                true,
            );

        if (!$isMemberAccess) {
            return false;
        }

        $receiverIndex = self::previousMeaningfulTokenIndex($tokens, $operatorIndex);
        $receiver = $receiverIndex !== null ? $tokens[$receiverIndex] : null;

        if (!\is_array($receiver)) {
            return false;
        }

        if ($receiver[0] === \T_VARIABLE) {
            return \in_array($receiver[1], $contextVariables, true);
        }

        if ($receiver[0] !== \T_STRING || !\in_array($receiver[1], $contextBearingFields, true)) {
            return false;
        }

        $fieldOperator = self::previousMeaningfulToken($tokens, $receiverIndex);

        return \is_array($fieldOperator)
            && \in_array($fieldOperator[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    /**
     * Every variable this file's own text says holds an `AnalysisContext`:
     * a parameter or property type-hinted `AnalysisContext $name`, plus
     * `$this` inside the file that declares the type. Textual and
     * file-scoped, matching this project's one calling convention for the type
     * rather than solving aliasing or cross-file flow.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string>
     */
    private static function contextTypedVariables(array $tokens): array
    {
        $variables = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_VARIABLE) {
                continue;
            }

            $previous = self::previousMeaningfulToken($tokens, $index);
            $previousText = \is_array($previous) ? $previous[1] : $previous;

            if ($previousText === self::CONTEXT_TYPE) {
                $variables[$token[1]] = true;
            }
        }

        if (self::declaresContextType($tokens)) {
            $variables['$this'] = true;
        }

        return array_keys($variables);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function declaresContextType(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_CLASS) {
                continue;
            }

            $nameIndex = self::nextMeaningfulTokenIndex($tokens, $index);
            $name = $nameIndex !== null ? $tokens[$nameIndex] : null;

            if (\is_array($name) && $name[0] === \T_STRING && $name[1] === self::CONTEXT_TYPE) {
                return true;
            }
        }

        return false;
    }

    /**
     * The public fields declared `AnalysisContext` anywhere in `src/`: the
     * carriers through which a chained read reaches a context. Measured, not
     * listed, so a new carrier joins the perimeter with the field that
     * introduces it — {@see itFindsThePublicFieldsThatCarryAContext()} is what
     * makes a change to this set visible.
     *
     * @return list<string> field names, sorted
     */
    private static function contextBearingFieldNames(string $root): array
    {
        /** @var array<string, list<string>> $cache */
        static $cache = [];

        if (isset($cache[$root])) {
            return $cache[$root];
        }

        $fields = [];

        foreach (self::productionPhpFiles($root) as $absolutePath) {
            $tokens = token_get_all(self::readSource($absolutePath));

            foreach ($tokens as $index => $token) {
                if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] !== self::CONTEXT_TYPE) {
                    continue;
                }

                $variableIndex = self::nextMeaningfulTokenIndex($tokens, $index);
                $variable = $variableIndex !== null ? $tokens[$variableIndex] : null;

                if (!\is_array($variable) || $variable[0] !== \T_VARIABLE) {
                    continue;
                }

                if (self::isPublicPropertyDeclaration($tokens, $index)) {
                    $fields[substr($variable[1], 1)] = true;
                }
            }
        }

        $names = array_keys($fields);
        sort($names);

        $cache[$root] = $names;

        return $names;
    }

    /**
     * Whether the type token at `$index` belongs to a public property
     * declaration — promoted or not. Nullability markers and `readonly` are
     * transparent; anything else in front means a plain parameter.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isPublicPropertyDeclaration(array $tokens, int $index): bool
    {
        for ($i = $index; $i >= 0;) {
            $previousIndex = self::previousMeaningfulTokenIndex($tokens, $i);

            if ($previousIndex === null) {
                return false;
            }

            $previous = $tokens[$previousIndex];

            if ($previous === '?') {
                $i = $previousIndex;

                continue;
            }

            if (\is_array($previous) && $previous[0] === \T_READONLY) {
                $i = $previousIndex;

                continue;
            }

            return \is_array($previous) && $previous[0] === \T_PUBLIC;
        }

        return false;
    }

    /**
     * Every production call site, by file and by the method that makes the
     * call. The enclosing method is the nearest named `function` above the
     * call; a call inside a closure is attributed to the named method holding
     * the closure, which is the granularity {@see KNOWN_CALL_SITES} names.
     *
     * @return array<string, list<string>> relative path => enclosing method names, sorted
     */
    private static function productionCallSiteIndex(string $root): array
    {
        $fields = self::contextBearingFieldNames($root);
        $index = [];

        foreach (self::productionPhpFiles($root) as $absolutePath) {
            $tokens = token_get_all(self::readSource($absolutePath));
            $sites = self::methodCallSites($tokens, self::contextTypedVariables($tokens), $fields);

            foreach ($sites as $siteIndex) {
                $index[self::relativePath($root, $absolutePath)][] = self::enclosingFunctionName($tokens, $siteIndex);
            }
        }

        ksort($index);

        return $index;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function enclosingFunctionName(array $tokens, int $index): string
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            $token = $tokens[$i];

            if (!\is_array($token) || $token[0] !== \T_FUNCTION) {
                continue;
            }

            $nameIndex = self::nextMeaningfulTokenIndex($tokens, $i);
            $name = $nameIndex !== null ? $tokens[$nameIndex] : null;

            if (\is_array($name) && $name[0] === \T_STRING) {
                return $name[1];
            }
        }

        return '{no enclosing named function}';
    }

    private static function isLegitimateDirectReader(string $relativePath): bool
    {
        return \array_key_exists($relativePath, self::LEGITIMATE_DIRECT_READERS);
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
        $previousIndex = self::previousMeaningfulTokenIndex($tokens, $index);

        return $previousIndex === null ? null : $tokens[$previousIndex];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function previousMeaningfulTokenIndex(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            $token = $tokens[$i];

            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
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

    private static function relativePath(string $root, string $absolutePath): string
    {
        return substr($absolutePath, \strlen($root) + 1);
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
