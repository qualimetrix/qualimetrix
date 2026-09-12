<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\RuleConfiguration\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Evidence\Complexity\CognitiveComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\NpathComplexityOptions;
use Qualimetrix\Analysis\Evidence\Coupling\CboOptions;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceOptions;
use Qualimetrix\Analysis\Evidence\Coupling\InstabilityOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusalWording;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Throwable;

/**
 * One case per position where a rule option key could go unrecognised, and one
 * per position where it must keep working.
 *
 * **Every case goes in through the configuration file, not through
 * {@see RuleOptionsRegistry::setConfigFileOptions()} directly.** The two are
 * not the same door: a direct write leaves a depth-2 key spelled the way the
 * test typed it, while `rules` is `PRESERVE_IMMEDIATE_CHILDREN` (ADR 0009), so
 * the real loader folds every key below the rule slug before the factory
 * exists. A refusal assembled over a direct write therefore prints
 * `max_warnign` where the product prints `maxWarnign`, and a test built that
 * way would pin a door no user has. The one place a direct write is used here
 * is the universal off-switch, whose subject is a *rule-level scalar* the
 * loader passes through untouched.
 *
 * The negative half is not decoration. A file that only asserts refusals passes
 * just as well against a factory that refuses everything, so each refusal here
 * is written beside the spelling that must still work — and the working half
 * asserts the value arrived, never merely that nothing was thrown.
 */
#[CoversClass(RuleOptionsFactory::class)]
#[CoversClass(RuleOptionRefusalWording::class)]
final class UnknownRuleOptionKeyRefusalTest extends TestCase
{
    // -- depth 2: a key written inside a level slot ---------------------------

    /**
     * Enumeration rows E53 (`warnign`), E55 (`warn`), E56 (`WARNING` — upper
     * case is not a spelling of `warning`, it is a different key), E57
     * (`max_warning`, the neighbouring slot's vocabulary) and E58 (the same
     * mistake in the other direction), plus E48 — a retired suppression key at
     * depth 2, where its replacement is not valid either, so the generic
     * sentence is the whole answer.
     *
     * The printed key is asserted verbatim: it is the folded spelling, which is
     * the stated limit of this seam, and an accidental improvement to it should
     * redden here rather than pass unnoticed.
     */
    #[Test]
    #[DataProvider('provideKeysNoLevelSlotAnswersFor')]
    public function itRefusesAnUnrecognisedKeyInsideALevelSlot(
        string $slot,
        string $writtenKey,
        string $printedKey,
        string $optionsAtThatLevel,
    ): void {
        $refusal = $this->refusalFrom(
            \sprintf("  complexity.ccn:\n    %s:\n      %s: 1\n      error: 2\n", $slot, $writtenKey),
            'complexity.ccn',
            ComplexityOptions::class,
        );

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString(
            \sprintf(
                'Option "%s" is not an option of rule "complexity.ccn" at level "%s".'
                . ' Options at that level: %s.',
                $printedKey,
                $slot,
                $optionsAtThatLevel,
            ),
            $refusal->getMessage(),
        );
        self::assertStringContainsString('Other levels of this rule take different options.', $refusal->getMessage());
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideKeysNoLevelSlotAnswersFor(): iterable
    {
        $callable = 'enabled, error, threshold, warning';
        $class = 'enabled, max-error, max-warning, threshold';

        yield 'E48 a retired suppression key inside a slot' => ['callable', 'exclude_paths', 'excludePaths', $callable];
        yield 'E53 a typo in a key the slot does take' => ['callable', 'warnign', 'warnign', $callable];
        yield 'E55 a truncation of one' => ['callable', 'warn', 'warn', $callable];
        yield 'E56 upper case, which the fold does not repair' => ['callable', 'WARNING', 'wARNING', $callable];
        yield 'E57 the sibling slot vocabulary in callable' => ['callable', 'max_warning', 'maxWarning', $callable];
        yield 'E58 the callable vocabulary in the class slot' => ['class', 'warning', 'warning', $class];
    }

    /**
     * Enumeration row E54: two unrecognised keys, and the refusal names the
     * first in document order rather than collecting both. The order is the
     * assertion — a collector would print `errro` too, and a walk that sorted
     * its subject would print it instead.
     */
    #[Test]
    public function itRefusesTheFirstUnrecognisedKeyInDocumentOrder(): void
    {
        $refusal = $this->refusalFrom(
            "  complexity.ccn:\n    callable:\n      warnign: 1\n      errro: 2\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString('Option "warnign"', $refusal->getMessage());
        self::assertStringNotContainsString('errro', $refusal->getMessage());
    }

    /**
     * Enumeration row E59: a slot holding a scalar has no keys to walk, so the
     * answer comes before the key comparison. No `{enabled: false}` advice —
     * nothing about `callable: 10` says an off-switch was meant.
     */
    #[Test]
    public function itRefusesALevelSlotHoldingSomethingOtherThanAMap(): void
    {
        $refusal = $this->refusalFrom(
            "  complexity.ccn:\n    callable: 10\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString(
            'Level "callable" of rule "complexity.ccn" takes a map of options, got int.',
            $refusal->getMessage(),
        );
        self::assertStringNotContainsString('enabled: false', $refusal->getMessage());
    }

    /**
     * `callable: false` is the one non-map a user plausibly means something by,
     * because `rules: {X: false}` is the idiom for the whole rule. It is a
     * different decision from E59's bare refusal, and the hint is what makes it
     * one.
     */
    #[Test]
    public function itRefusesALevelSlotWrittenFalseAndNamesTheOffSwitchToWriteInstead(): void
    {
        $refusal = $this->refusalFrom(
            "  complexity.ccn:\n    callable: false\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString(
            'Level "callable" of rule "complexity.ccn" takes a map of options, got bool.'
            . ' To switch one level off write "callable: {enabled: false}".',
            $refusal->getMessage(),
        );
    }

    /**
     * Enumeration row E60. An empty level block means what an omitted one
     * means, and this case exists so that a later tightening of the not-a-map
     * branch has to delete a green test rather than merely not notice: silence
     * here is a decision, and every other silence in this seam was removed.
     */
    #[Test]
    public function itAcceptsAnEmptyLevelSlotInSilence(): void
    {
        $options = $this->optionsFrom(
            "  complexity.ccn:\n    callable:\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );

        self::assertInstanceOf(ComplexityOptions::class, $options);
        self::assertSame(10, $options->callable->warning);
        self::assertSame(30, $options->class->maxWarning);
    }

    /**
     * Enumeration row E61, stated rather than assumed: the declaration is
     * kebab, the comparison folds, so all three spellings of one key are one
     * key — and each of them arrives, which is the half a "not refused"
     * assertion would miss.
     */
    #[Test]
    #[DataProvider('provideEquivalentSpellingsOfOneLevelKey')]
    public function itAcceptsEveryCanonicalSpellingOfALevelKey(string $spelling): void
    {
        $options = $this->optionsFrom(
            \sprintf("  complexity.ccn:\n    class:\n      %s: 5\n", $spelling),
            'complexity.ccn',
            ComplexityOptions::class,
        );

        self::assertInstanceOf(ComplexityOptions::class, $options);
        self::assertSame(5, $options->class->maxWarning);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEquivalentSpellingsOfOneLevelKey(): iterable
    {
        yield 'snake' => ['max_warning'];
        yield 'camel' => ['maxWarning'];
        yield 'kebab' => ['max-warning'];
    }

    // -- the four discriminators ---------------------------------------------

    /**
     * Fact 1, in one test with two halves. Top-level `warning` sits in
     * `CboOptions`' branch *condition*, so it works alone and is declared; on
     * the three complexity wrappers it sits inside a branch opened by
     * `threshold`, so alone it does nothing and is refused. Same input shape,
     * opposite verdicts — a simplification that unified them would redden here.
     */
    #[Test]
    public function itAcceptsATopLevelWarningWhereItWorksAloneAndRefusesItWhereItDoesNot(): void
    {
        $accepted = $this->optionsFrom("  coupling.cbo:\n    warning: 3\n", 'coupling.cbo', CboOptions::class);

        self::assertInstanceOf(CboOptions::class, $accepted);
        self::assertSame(3, $accepted->class->warning);

        $refusal = $this->refusalFrom("  complexity.ccn:\n    warning: 3\n", 'complexity.ccn', ComplexityOptions::class);

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString(
            'Option "warning" is not an option of rule "complexity.ccn". Options here:',
            $refusal->getMessage(),
        );
    }

    /**
     * Fact 2, four assertions on one rule: the two slots of `complexity.ccn`
     * accept disjoint key sets, so there is no single "keys allowed at a level"
     * list and a walk that compared against one would be wrong in both
     * directions at once.
     */
    #[Test]
    public function itAnswersEachLevelSlotAgainstItsOwnVocabulary(): void
    {
        $classSlot = $this->optionsFrom(
            "  complexity.ccn:\n    class:\n      max_warning: 3\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );
        self::assertInstanceOf(ComplexityOptions::class, $classSlot);
        self::assertSame(3, $classSlot->class->maxWarning);

        $callableSlot = $this->optionsFrom(
            "  complexity.ccn:\n    callable:\n      warning: 3\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );
        self::assertInstanceOf(ComplexityOptions::class, $callableSlot);
        self::assertSame(3, $callableSlot->callable->warning);

        $inClass = $this->refusalFrom(
            "  complexity.ccn:\n    class:\n      warning: 3\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );
        self::assertInstanceOf(ConfigurationRefusal::class, $inClass);
        self::assertStringContainsString('at level "class". Options at that level: enabled, max-error, max-warning, threshold.', $inClass->getMessage());

        $inCallable = $this->refusalFrom(
            "  complexity.ccn:\n    callable:\n      max_warning: 3\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );
        self::assertInstanceOf(ConfigurationRefusal::class, $inCallable);
        self::assertStringContainsString('at level "callable". Options at that level: enabled, error, threshold, warning.', $inCallable->getMessage());
    }

    /**
     * Fact 3, pairs #23–#26. The class reads these keys in order to refuse them
     * in words the generic sentence cannot produce — a migration instruction,
     * or the difference between an `enabled: false` that asks for the status
     * quo and an `enabled: true` that promises what the class cannot do. The
     * discriminator is therefore both halves: the class's own exception type
     * and text, and the *absence* of the generic sentence above it.
     *
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    #[Test]
    #[DataProvider('provideKeysTheClassAnswersForItself')]
    public function itLeavesAKeyTheClassAnswersForToTheClassItself(
        string $ruleName,
        string $optionsClass,
        string $key,
        string $ownWords,
    ): void {
        $refusal = $this->refusalFrom(
            \sprintf("  %s:\n    %s: %s\n", $ruleName, $key, 'warning'),
            $ruleName,
            $optionsClass,
        );

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString($ownWords, $refusal->getMessage());
        self::assertStringNotContainsString('is not an option of rule', $refusal->getMessage());
    }

    /**
     * @return iterable<string, array{string, class-string<RuleOptionsInterface>, string, string}>
     */
    public static function provideKeysTheClassAnswersForItself(): iterable
    {
        $removed = 'no longer exists';

        yield '#24 unreachable-layer-severity' => [
            'architecture.layer-violation',
            LayerViolationOptions::class,
            'unreachable_layer_severity',
            $removed,
        ];
        yield '#25 potential-shadow-severity' => [
            'architecture.layer-violation',
            LayerViolationOptions::class,
            'potential_shadow_severity',
            $removed,
        ];
        yield '#26 empty-template-severity' => [
            'architecture.layer-violation',
            LayerViolationOptions::class,
            'empty_template_severity',
            $removed,
        ];
    }

    /**
     * Pair #23, the half of Fact 3 whose two spellings mean different things:
     * `enabled: true` promises to turn a gate on and cannot, and is refused in
     * the class's own words; `enabled: false` beside the default `ignore` mode
     * asks for what is already the case and is accepted in silence. A generic
     * refusal would have to pick one answer for both.
     */
    #[Test]
    public function itLetsTheUnassignedClassRuleAnswerForEnabledInBothDirections(): void
    {
        $refusal = $this->refusalFrom(
            "  architecture.unassigned-class:\n    enabled: true\n",
            'architecture.unassigned-class',
            UnassignedClassOptions::class,
        );

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString('"mode" is the only switch', $refusal->getMessage());
        self::assertStringNotContainsString('is not an option of rule', $refusal->getMessage());

        $accepted = $this->optionsFrom(
            "  architecture.unassigned-class:\n    enabled: false\n",
            'architecture.unassigned-class',
            UnassignedClassOptions::class,
        );

        self::assertInstanceOf(UnassignedClassOptions::class, $accepted);
    }

    /**
     * Fact 4, pairs #27–#36 — the group most easily faked. `threshold` is read
     * through `ThresholdParser::parse()`'s default argument and named by no
     * constructor, so a declaration transcribed from constructor parameters
     * would drop it in all ten slots at once. A test asserting only "not
     * refused" would pass against a declaration that accepted the key and threw
     * the value away, so what is asserted is the severity the threshold
     * produces.
     *
     * @param class-string<HierarchicalRuleOptionsInterface> $optionsClass
     */
    #[Test]
    #[DataProvider('provideLevelSlotsTakingTheThresholdShorthand')]
    public function itAppliesTheThresholdShorthandInsideEveryLevelSlot(
        string $ruleName,
        string $optionsClass,
        string $slot,
        int|float $threshold,
    ): void {
        $options = $this->optionsFrom(
            \sprintf("  %s:\n    %s:\n      threshold: %s\n", $ruleName, $slot, $threshold),
            $ruleName,
            $optionsClass,
        );

        self::assertInstanceOf(HierarchicalRuleOptionsInterface::class, $options);

        $level = $options->forLevel(SymbolLevel::from($slot));

        self::assertSame(Severity::Error, $level->getSeverity($threshold), 'the threshold did not arrive');
        self::assertNull($level->getSeverity($threshold / 10), 'the threshold arrived but is not the one written');
    }

    /**
     * @return iterable<string, array{string, class-string<HierarchicalRuleOptionsInterface>, string, int|float}>
     */
    public static function provideLevelSlotsTakingTheThresholdShorthand(): iterable
    {
        yield '#27 cognitive callable' => ['complexity.cognitive', CognitiveComplexityOptions::class, 'callable', 7];
        yield '#28 cognitive class' => ['complexity.cognitive', CognitiveComplexityOptions::class, 'class', 7];
        yield '#29 ccn callable' => ['complexity.ccn', ComplexityOptions::class, 'callable', 7];
        yield '#30 ccn class' => ['complexity.ccn', ComplexityOptions::class, 'class', 7];
        yield '#31 npath callable' => ['complexity.npath', NpathComplexityOptions::class, 'callable', 7];
        yield '#32 npath class' => ['complexity.npath', NpathComplexityOptions::class, 'class', 7];
        yield '#33 cbo class' => ['coupling.cbo', CboOptions::class, 'class', 7];
        yield '#34 cbo namespace' => ['coupling.cbo', CboOptions::class, 'namespace', 7];
        yield '#35 instability class' => ['coupling.instability', InstabilityOptions::class, 'class', 0.5];
        yield '#36 instability namespace' => ['coupling.instability', InstabilityOptions::class, 'namespace', 0.5];
    }

    // -- the pairs that change what a spelling does ---------------------------

    /**
     * Pairs #4/#5/#9/#10/#14/#15. Top-level `warning`/`error` on a hierarchical
     * complexity rule are read only inside the branch `threshold` opens, so
     * written alone they never did anything; they are refused rather than
     * declared, and the refusal names the slot vocabulary instead.
     *
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    #[Test]
    #[DataProvider('provideTopLevelKeysTheComplexityWrappersRefuse')]
    public function itRefusesTopLevelWarningAndErrorOnAHierarchicalComplexityRule(
        string $ruleName,
        string $optionsClass,
        string $key,
    ): void {
        $refusal = $this->refusalFrom(\sprintf("  %s:\n    %s: 3\n", $ruleName, $key), $ruleName, $optionsClass);

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString(
            \sprintf('Option "%s" is not an option of rule "%s".', $key, $ruleName),
            $refusal->getMessage(),
        );
        self::assertStringContainsString('callable, class, enabled,', $refusal->getMessage());
    }

    /**
     * @return iterable<string, array{string, class-string<RuleOptionsInterface>, string}>
     */
    public static function provideTopLevelKeysTheComplexityWrappersRefuse(): iterable
    {
        yield '#4 ccn warning' => ['complexity.ccn', ComplexityOptions::class, 'warning'];
        yield '#5 ccn error' => ['complexity.ccn', ComplexityOptions::class, 'error'];
        yield '#9 cognitive warning' => ['complexity.cognitive', CognitiveComplexityOptions::class, 'warning'];
        yield '#10 cognitive error' => ['complexity.cognitive', CognitiveComplexityOptions::class, 'error'];
        yield '#14 npath warning' => ['complexity.npath', NpathComplexityOptions::class, 'warning'];
        yield '#15 npath error' => ['complexity.npath', NpathComplexityOptions::class, 'error'];
    }

    /**
     * Pairs #2/#3/#7/#8/#12/#13/#22 — the seven undeclared aliases. Each worked
     * and each duplicated a key that is declared and does the same thing, so
     * each is removed rather than enshrined; the refusal is what tells a
     * configuration written against the old contract that it stopped applying,
     * instead of leaving it silently inert.
     *
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    #[Test]
    #[DataProvider('provideRemovedAliases')]
    public function itRefusesEveryRemovedAliasAndKeepsTheKeyItAliased(
        string $ruleName,
        string $optionsClass,
        string $alias,
        string $printedAlias,
        string $survivingKey,
    ): void {
        $refusal = $this->refusalFrom(\sprintf("  %s:\n    %s: 3\n", $ruleName, $alias), $ruleName, $optionsClass);

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString(
            \sprintf('Option "%s" is not an option of rule "%s".', $printedAlias, $ruleName),
            $refusal->getMessage(),
        );
        self::assertStringContainsString($survivingKey, $refusal->getMessage(), 'the refusal must name the key that replaced the alias');
    }

    /**
     * @return iterable<string, array{string, class-string<RuleOptionsInterface>, string, string, string}>
     */
    public static function provideRemovedAliases(): iterable
    {
        yield '#2 ccn warning-threshold' => ['complexity.ccn', ComplexityOptions::class, 'warning_threshold', 'warningThreshold', 'threshold'];
        yield '#3 ccn error-threshold' => ['complexity.ccn', ComplexityOptions::class, 'error_threshold', 'errorThreshold', 'threshold'];
        yield '#7 cognitive warning-threshold' => ['complexity.cognitive', CognitiveComplexityOptions::class, 'warning_threshold', 'warningThreshold', 'threshold'];
        yield '#8 cognitive error-threshold' => ['complexity.cognitive', CognitiveComplexityOptions::class, 'error_threshold', 'errorThreshold', 'threshold'];
        yield '#12 npath warning-threshold' => ['complexity.npath', NpathComplexityOptions::class, 'warning_threshold', 'warningThreshold', 'threshold'];
        yield '#13 npath error-threshold' => ['complexity.npath', NpathComplexityOptions::class, 'error_threshold', 'errorThreshold', 'threshold'];
        yield '#22 distance project-namespaces' => ['coupling.distance', DistanceOptions::class, 'project_namespaces', 'projectNamespaces', 'include-namespaces'];
    }

    /**
     * Pairs #17/#18/#20/#21, the negative half of the alias removals above:
     * these four flat keys sit in their branch's *condition*, work alone, and
     * are therefore declared — removing them for symmetry with the complexity
     * wrappers would have broken a configuration that does something today.
     *
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    #[Test]
    #[DataProvider('provideFlatCouplingKeysThatWorkAlone')]
    public function itAcceptsTheFlatCouplingKeysThatWorkAlone(
        string $ruleName,
        string $optionsClass,
        string $key,
        int|float $value,
    ): void {
        $options = $this->optionsFrom(
            \sprintf("  %s:\n    %s: %s\n", $ruleName, $key, $value),
            $ruleName,
            $optionsClass,
        );

        self::assertInstanceOf(HierarchicalRuleOptionsInterface::class, $options);
        self::assertNotNull($options->forLevel(SymbolLevel::Class_)->getSeverity($value));
    }

    /**
     * @return iterable<string, array{string, class-string<RuleOptionsInterface>, string, int|float}>
     */
    public static function provideFlatCouplingKeysThatWorkAlone(): iterable
    {
        yield '#17 cbo warning' => ['coupling.cbo', CboOptions::class, 'warning', 3];
        yield '#18 cbo error' => ['coupling.cbo', CboOptions::class, 'error', 4];
        yield '#20 instability max-warning' => ['coupling.instability', InstabilityOptions::class, 'max_warning', 0.5];
        yield '#21 instability max-error' => ['coupling.instability', InstabilityOptions::class, 'max_error', 0.6];
    }

    // -- the framework keys, which no options class declares -------------------

    /**
     * The three framework keys are taken out of the config before the walk, so
     * a correctly spelled one never reaches the comparison — and this asserts
     * it arrived where it was going rather than merely that nothing was thrown.
     */
    #[Test]
    public function itAcceptsAFrameworkKeyAtTheTopLevelOfARule(): void
    {
        $registry = new RuleOptionsRegistry();
        $this->configure($registry, "  complexity.ccn:\n    suppress_paths: ['src/Generated/*']\n");
        (new RuleOptionsFactory($registry))->create('complexity.ccn', ComplexityOptions::class);

        self::assertTrue($registry->isPathExcluded('complexity.ccn', RelativePath::fromString('src/Generated/Table.php')));
        self::assertFalse($registry->isPathExcluded('complexity.ccn', RelativePath::fromString('src/Handwritten/Table.php')));
    }

    /**
     * A framework key is declared by no options class, so a refusal built from
     * the declaration alone would list the allowed keys without listing the
     * three that are allowed — naming the fix for `suppress_path` nowhere. The
     * printed set is the whole answer here, since there is no "did you mean".
     */
    #[Test]
    public function itRefusesAMistypedFrameworkKeyAndPrintsTheSpellingThatWorks(): void
    {
        $refusal = $this->refusalFrom(
            "  complexity.ccn:\n    suppress_path: ['*']\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString('Option "suppressPath" is not an option of rule "complexity.ccn".', $refusal->getMessage());
        self::assertStringContainsString('suppress-paths', $refusal->getMessage());
    }

    /**
     * Legal at depth 1, unknown inside a slot: the framework strips them from
     * the rule's own config and never looks into a level block, so a
     * `suppress_paths` written there would silently suppress nothing.
     */
    #[Test]
    public function itRefusesAFrameworkKeyWrittenInsideALevelSlot(): void
    {
        $refusal = $this->refusalFrom(
            "  complexity.ccn:\n    callable:\n      suppress_paths: ['*']\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal);
        self::assertStringContainsString('at level "callable"', $refusal->getMessage());
        self::assertStringNotContainsString('suppress-paths', $refusal->getMessage(), 'the slot does not take it, so it must not be advertised there');
    }

    /**
     * ADR 0047's refusal runs before this walk and stays at depth 1, where it
     * can name the replacement. At depth 2 the retired key and its replacement
     * are both invalid, so one sentence covers both and the generic one is it.
     */
    #[Test]
    public function itKeepsTheRetiredKeyRefusalAtDepthOneAndTheGenericOneInsideASlot(): void
    {
        $registry = new RuleOptionsRegistry();
        $registry->setConfigFileOptions(['complexity.ccn' => ['excludePaths' => ['*']]]);

        $atDepthOne = $this->capture(
            static fn() => (new RuleOptionsFactory($registry))->create('complexity.ccn', ComplexityOptions::class),
        );

        self::assertInstanceOf(ConfigurationRefusal::class, $atDepthOne);
        self::assertStringContainsString('The "excludePaths" option was retired', $atDepthOne->getMessage());
        self::assertStringContainsString('suppressPaths', $atDepthOne->getMessage());

        $insideASlot = $this->refusalFrom(
            "  complexity.ccn:\n    callable:\n      exclude_paths: ['*']\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );

        self::assertInstanceOf(ConfigurationRefusal::class, $insideASlot);
        self::assertStringContainsString('is not an option of rule', $insideASlot->getMessage());
        self::assertStringNotContainsString('was retired', $insideASlot->getMessage());
    }

    // -- the property the whole population must keep ---------------------------

    /**
     * `rules: {X: false}` is normalised into `{enabled: false}` for *any* rule,
     * so once an unknown key means exit 3, a class that neither accepts nor
     * answers `enabled` turns the product's universal off-switch into a hard
     * error for the rule it is written against. Every registered rule satisfies
     * this today — but by property of the population, not of the design, which
     * is why the subject is the container's registry rather than a written
     * list: a rule added tomorrow is in this test the day it is registered.
     *
     * The scalar goes in through the registry because that is what the loader
     * hands over untouched for a rule configured with a bare `false`.
     */
    #[Test]
    public function itLeavesTheUniversalOffSwitchWorkingForEveryRegisteredRule(): void
    {
        $execution = (new ContainerFactory())->create()->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $execution);

        $rules = $execution->allRules();
        self::assertNotSame([], $rules);

        foreach ($rules as $rule) {
            $registry = new RuleOptionsRegistry();
            $registry->setConfigFileOptions([$rule->name => false]);

            $options = null;
            $refusal = $this->capture(
                static function () use ($registry, $rule, &$options): void {
                    $options = (new RuleOptionsFactory($registry))->create($rule->name, $rule->optionsClass);
                },
            );

            self::assertNull(
                $refusal,
                \sprintf('"rules: {%s: false}" must switch the rule off, not refuse: %s', $rule->name, $refusal?->getMessage() ?? ''),
            );
            self::assertInstanceOf(RuleOptionsInterface::class, $options);
            self::assertFalse($options->isEnabled(), \sprintf('"rules: {%s: false}" was accepted without switching the rule off', $rule->name));
        }
    }

    /**
     * Every `#[CliAlias]` the product ships addresses a key the class at that
     * flag's depth answers for.
     *
     * The two sides were only ever kept in agreement by hand — the alias names
     * an option in the attribute, the class declares its key set, and nothing
     * compared them. While an unrecognised key was a warning, a disagreement
     * cost a line on `stderr`; now it costs exit 3 on a flag the product itself
     * declares, which a user has no way around. All 80 agree today, so this is
     * the invariant being closed, not a defect being fixed.
     *
     * The alias goes in through its own door and no other: the parser folds the
     * spelling the attribute's author wrote, and the factory expands the dot of
     * a `slot.key` alias into the nesting depth 2 is walked at. Rebuilding
     * either step here would let this test agree with a defect in it.
     *
     * The value is deliberately not the point: an alias naming a string option
     * is refused for its `1` by the options class itself, and that refusal is
     * the class speaking about a value, not about a key. Only a refusal in the
     * words of {@see RuleOptionRefusalWording} is a failure here, and the two
     * sentences are asked for their own marker rather than transcribed.
     */
    #[Test]
    public function itLetsEveryCliAliasTheProductShipsThroughTheKeyWalk(): void
    {
        $unknownKey = 'is not an option';
        $notAMap = 'takes a map of options';

        self::assertStringContainsString($unknownKey, RuleOptionRefusalWording::notAnOptionOfRule('k', 'r', []));
        self::assertStringContainsString($unknownKey, RuleOptionRefusalWording::notAnOptionAtLevel('k', 'r', 'class', []));
        self::assertStringContainsString($notAMap, RuleOptionRefusalWording::levelTakesAMapOfOptions('class', 'r', 1));

        $container = (new ContainerFactory())->create();

        $ruleRegistry = $container->get(RuleRegistryInterface::class);
        self::assertInstanceOf(RuleRegistryInterface::class, $ruleRegistry);

        $execution = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $execution);

        $optionsClasses = [];
        foreach ($execution->allRules() as $rule) {
            $optionsClasses[$rule->name] = $rule->optionsClass;
        }

        $parser = (new RuleOptionsParserFactory())->createFromClasses($ruleRegistry->getClasses());
        $aliases = $parser->getAliasNames();

        self::assertNotSame([], $aliases, 'No CLI alias found — the subject of this test is empty');

        foreach ($aliases as $alias) {
            $parsed = $parser->parseShortAlias($alias, 1);
            self::assertIsArray($parsed, \sprintf('Alias "%s" is registered but parses to nothing', $alias));

            $ruleName = $parsed['rule'];
            self::assertArrayHasKey($ruleName, $optionsClasses, \sprintf('Alias "%s" names rule "%s", which is not registered', $alias, $ruleName));

            $registry = new RuleOptionsRegistry();
            $registry->setCliOptions($ruleName, [$parsed['option'] => $parsed['value']]);

            $refusal = $this->capture(
                static function () use ($registry, $ruleName, $optionsClasses): void {
                    (new RuleOptionsFactory($registry))->create($ruleName, $optionsClasses[$ruleName]);
                },
            );

            $message = $refusal?->getMessage() ?? '';

            self::assertStringNotContainsString(
                $unknownKey,
                $message,
                \sprintf('--%s writes "%s" on rule "%s", which the key walk does not recognise', $alias, $parsed['option'], $ruleName),
            );
            self::assertStringNotContainsString(
                $notAMap,
                $message,
                \sprintf('--%s writes "%s" on rule "%s", which the key walk reads as a level slot', $alias, $parsed['option'], $ruleName),
            );
        }
    }

    /**
     * The broad negative case. A configuration written correctly at both depths
     * passes the walk untouched and every value arrives — the assertion that
     * tells "the rule works" apart from "the rule refuses everything", which no
     * amount of red cases above can make.
     */
    #[Test]
    public function itLeavesACorrectlyWrittenConfigurationUntouchedAtBothDepths(): void
    {
        $options = $this->optionsFrom(
            "  complexity.ccn:\n    enabled: true\n    suppress_paths: ['src/Generated/*']\n"
            . "    callable:\n      warning: 4\n      error: 9\n"
            . "    class:\n      max_warning: 11\n      max_error: 22\n",
            'complexity.ccn',
            ComplexityOptions::class,
        );

        self::assertInstanceOf(ComplexityOptions::class, $options);
        self::assertSame(4, $options->callable->warning);
        self::assertSame(9, $options->callable->error);
        self::assertSame(11, $options->class->maxWarning);
        self::assertSame(22, $options->class->maxError);
    }

    // -- the door ---------------------------------------------------------------

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    private function optionsFrom(string $rulesBlock, string $ruleName, string $optionsClass): RuleOptionsInterface
    {
        $registry = new RuleOptionsRegistry();
        $this->configure($registry, $rulesBlock);

        return (new RuleOptionsFactory($registry))->create($ruleName, $optionsClass);
    }

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    private function refusalFrom(string $rulesBlock, string $ruleName, string $optionsClass): Throwable
    {
        $refusal = $this->capture(fn() => $this->optionsFrom($rulesBlock, $ruleName, $optionsClass));

        self::assertNotNull($refusal, 'the configuration was accepted where a refusal was expected');

        return $refusal;
    }

    /**
     * Loads the block the way `qmx.yaml` is loaded, so the keys reaching the
     * factory are folded exactly as a user's would be.
     */
    private function configure(RuleOptionsRegistry $registry, string $rulesBlock): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-rule-option-key-');
        self::assertNotFalse($path);
        file_put_contents($path, "rules:\n" . $rulesBlock);

        try {
            $config = (new YamlConfigLoader())->load($path);
        } finally {
            unlink($path);
        }

        /** @var array<string, mixed> $rules */
        $rules = $config['rules'] ?? [];
        $registry->setConfigFileOptions($rules);
    }

    private function capture(callable $act): ?Throwable
    {
        try {
            $act();
        } catch (Throwable $refusal) {
            return $refusal;
        }

        return null;
    }
}
