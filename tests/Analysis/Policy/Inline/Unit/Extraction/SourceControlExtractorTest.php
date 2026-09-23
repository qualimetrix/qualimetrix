<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Extraction;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationBinding;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusal;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusalReason;
use Qualimetrix\Analysis\Policy\Inline\Contract\SourceControls;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Extraction\SourceControlExtractor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionMethod;

#[CoversClass(SourceControls::class)]
#[CoversClass(SourceControlExtractor::class)]
final class SourceControlExtractorTest extends TestCase
{
    #[Test]
    public function itKeepsPhysicalControlsAndBindsSymbolControlsAndMalformedThresholds(): void
    {
        $source = <<<'PHP'
            <?php
            /** @qmx-ignore-file size.loc file reason */
            class Named
            {
                /** @qmx-ignore complexity.ccn class reason */
                public function run(
                    /** @qmx-ignore-next-line size.method-count next reason */
                    int $value,
                ): void {}

                /** @qmx-threshold complexity.ccn broken */
                public function invalid(): void {}
            }
            PHP;
        $ast = $this->parse($source);
        $nodes = new NodeFinder();
        $class = $nodes->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        $methods = $nodes->findInstanceOf($ast, Node\Stmt\ClassMethod::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertCount(2, $methods);

        $file = RelativePath::fromString('src/Example.php');
        $classDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'Named'), $file, DeclarationOrdinal::fromRank(0));
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Named'));
        $run = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $invalid = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'invalid'), $file, DeclarationOrdinal::fromRank(0));
        $classMetrics = new ClassWithMetrics($classDeclaration, $class->getStartFilePos(), $class->getStartLine(), new MetricBag());
        $callables = [
            new CallableWithMetrics($run, $methods[0]->getStartFilePos(), CallableKind::Method, null, $classDeclaration, $owner, new MetricBag()),
            new CallableWithMetrics($invalid, $methods[1]->getStartFilePos(), CallableKind::Method, null, $classDeclaration, $owner, new MetricBag()),
        ];
        $classes = [
            $classMetrics->subject->toCanonical() => [
                'subject' => $classMetrics->subject,
                'metrics' => $classMetrics->metrics,
                'line' => $classMetrics->line,
                'start' => $classMetrics->startFilePos,
            ],
        ];

        $controls = (new SourceControlExtractor())->extract(
            $ast,
            $source,
            $file,
            $callables,
            $classes,
        );

        self::assertContains(SuppressionType::File, array_map(static fn($suppression): SuppressionType => $suppression->type, $controls->suppressions));
        self::assertContains(SuppressionType::NextLine, array_map(static fn($suppression): SuppressionType => $suppression->type, $controls->suppressions));
        $symbol = array_values(array_filter($controls->suppressions, static fn($suppression): bool => $suppression->type === SuppressionType::Symbol));
        self::assertCount(1, $symbol);
        self::assertSame($run->toCanonical(), $symbol[0]->binding?->subject->toCanonical());
        self::assertCount(0, $controls->thresholdOverrides);
        self::assertCount(1, $controls->thresholdDiagnostics);
        self::assertSame($invalid->toCanonical(), $controls->thresholdDiagnostics[0]->subject->toCanonical());
    }

    #[Test]
    public function itKeepsDistinctControlScopesWhileCollapsingTrueDuplicates(): void
    {
        $subject = new ClassWithMetrics(
            DeclarationPath::of(SymbolPath::forClass('App', 'Named'), RelativePath::fromString('src/Example.php'), DeclarationOrdinal::fromRank(0)),
            10,
            1,
            new MetricBag(),
        )->subject;
        $classControl = new Suppression('complexity.ccn', 'reason', 4, SuppressionType::Symbol, new DeclarationBinding($subject, ControlScope::Class_, 12));
        $callableControl = new Suppression('complexity.ccn', 'reason', 4, SuppressionType::Symbol, new DeclarationBinding($subject, ControlScope::Callable, 12));
        $method = new ReflectionMethod(SourceControlExtractor::class, 'deduplicate');

        /** @var list<Suppression> $deduplicated */
        $deduplicated = $method->invoke(null, [$classControl, $callableControl, $classControl]);

        self::assertCount(2, $deduplicated);
        self::assertSame([
            ControlScope::Class_,
            ControlScope::Callable,
        ], array_map(
            static fn(Suppression $suppression): ControlScope => $suppression->binding->controlScope
                ?? throw new LogicException('Expected a symbol control scope'),
            $deduplicated,
        ));
    }

    /**
     * Two producers giving one physical declaration different ordinals must
     * not be merged into one binding. The ordinal is part of identity ({@see
     * DeclarationControlBindings::assertCompatibleSourceMetadata()}), so the
     * same disagreement is now rejected outright rather than silently
     * applying one control to both.
     */
    #[Test]
    public function itRejectsSourceControlBindingWhenTwoOrdinalsClaimOnePhysicalPosition(): void
    {
        $source = "<?php\n/** @qmx-ignore complexity.ccn collision */\nfunction run(int \$value): void {}\n";
        $ast = $this->parse($source);
        $function = (new NodeFinder())->findFirstInstanceOf($ast, Node\Stmt\Function_::class);
        self::assertInstanceOf(Node\Stmt\Function_::class, $function);

        $file = RelativePath::fromString('src/Example.php');
        $first = DeclarationPath::of(SymbolPath::forGlobalFunction('App', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of(SymbolPath::forGlobalFunction('App', 'run'), $file, DeclarationOrdinal::fromRank(1));

        $this->expectException(LogicException::class);
        (new SourceControlExtractor())->extract(
            $ast,
            $source,
            $file,
            [
                new CallableWithMetrics($first, $function->getStartFilePos(), CallableKind::Function, null, null, null, new MetricBag()),
                new CallableWithMetrics($second, $function->getStartFilePos(), CallableKind::Function, null, null, null, new MetricBag()),
            ],
            [],
        );
    }

    #[Test]
    public function itExtractsSourceControlsWithoutRunDeclarationBindings(): void
    {
        $extractor = new SourceControlExtractor();
        $source = "<?php\n// @qmx-ignore-file complexity\nfinal class Example {}\n";
        $controls = $extractor->extract(
            $this->parse($source),
            $source,
            RelativePath::fromString('src/Example.php'),
            [],
            [],
        );

        self::assertCount(1, $controls->suppressions);
        self::assertSame(SuppressionType::File, $controls->suppressions[0]->type);
    }

    /**
     * A physical directive is bound to a line and a file, so the kind of
     * statement it stands above is none of its business. The node-type list
     * that used to gate this said otherwise for `if`, `foreach`, `return`,
     * `namespace` and `use` — and only for docblocks, since a line comment
     * carrying the tag was let through by a second condition that excluded
     * them.
     *
     * @param non-empty-string $source
     * @param non-empty-string $carrier
     */
    #[Test]
    #[DataProvider('providePhysicalDirectiveCarriers')]
    public function itReadsAPhysicalDirectiveWhateverItIsWrittenOn(string $source, string $carrier, SuppressionType $type): void
    {
        $controls = (new SourceControlExtractor())->extract(
            $this->parse($source),
            $source,
            RelativePath::fromString('src/Example.php'),
            [],
            [],
        );

        self::assertCount(1, $controls->suppressions, $carrier);
        self::assertSame($type, $controls->suppressions[0]->type, $carrier);
        self::assertSame('complexity.ccn', $controls->suppressions[0]->rule, $carrier);
    }

    /** @return iterable<string, array{non-empty-string, non-empty-string, SuppressionType}> */
    public static function providePhysicalDirectiveCarriers(): iterable
    {
        $body = static fn(string $statement): string => "<?php\ndeclare(strict_types=1);\nnamespace Demo;\nclass Example {\n    public function m(array \$xs): int {\n{$statement}\n        return 0;\n    }\n}\n";

        yield 'docblock above an if' => [
            $body("        /** @qmx-ignore-next-line complexity.ccn */\n        if (\$xs !== []) { return 1; }"),
            'docblock above an if',
            SuppressionType::NextLine,
        ];
        yield 'block comment above a foreach' => [
            $body("        /* @qmx-ignore-next-line complexity.ccn */\n        foreach (\$xs as \$x) { return (int) \$x; }"),
            'block comment above a foreach',
            SuppressionType::NextLine,
        ];
        yield 'docblock above a foreach' => [
            $body("        /** @qmx-ignore-next-line complexity.ccn */\n        foreach (\$xs as \$x) { return (int) \$x; }"),
            'docblock above a foreach',
            SuppressionType::NextLine,
        ];
        yield 'docblock above a return' => [
            $body("        /** @qmx-ignore-next-line complexity.ccn */\n        return \$xs === [] ? 0 : 1;"),
            'docblock above a return',
            SuppressionType::NextLine,
        ];
        yield 'file form in a docblock above namespace, after declare' => [
            "<?php\ndeclare(strict_types=1);\n/** @qmx-ignore-file complexity.ccn */\nnamespace Demo;\nclass Example {}\n",
            'file form above namespace',
            SuppressionType::File,
        ];
        yield 'file form in a docblock above use' => [
            "<?php\ndeclare(strict_types=1);\nnamespace Demo;\n/** @qmx-ignore-file complexity.ccn */\nuse ArrayObject;\nclass Example { public ?ArrayObject \$o = null; }\n",
            'file form above use',
            SuppressionType::File,
        ];
    }

    /**
     * The declaration form on a statement used to throw out of extraction, and
     * the exception was not contained: the file failed to process, so one
     * misplaced annotation cost every metric and every finding in it.
     */
    #[Test]
    public function itRefusesADeclarationFormWrittenWhereNothingBinds(): void
    {
        $source = "<?php\ndeclare(strict_types=1);\nnamespace Demo;\nclass Example {\n    public function m(int \$a): int {\n        // @qmx-ignore complexity.ccn -- on a statement\n        if (\$a > 0) { return 1; }\n        return 0;\n    }\n}\n";
        $controls = (new SourceControlExtractor())->extract(
            $this->parse($source),
            $source,
            RelativePath::fromString('src/Example.php'),
            [],
            [],
        );

        self::assertCount(1, $controls->suppressions);
        self::assertSame(
            DirectiveRefusalReason::NoDeclarationToBind,
            $controls->suppressions[0]->refusal?->reason,
        );
        self::assertSame(6, $controls->suppressions[0]->line);
        self::assertFalse($controls->suppressions[0]->matches('complexity.ccn', null));
    }

    /**
     * The carrier is not supposed to change what a directive means, and for
     * the channelless form it did: the docblock and block-comment carriers
     * read their own closing delimiter as the argument `*`, so the tag became
     * the widest directive there is instead of the refusal the line-comment
     * carrier already produced. Only the line comment, whose text ends
     * without a delimiter, was refused.
     */
    #[Test]
    #[DataProvider('provideChannellessDirectiveCarriers')]
    public function itRefusesAChannellessDirectiveInEveryCommentCarrier(string $source, string $carrier, string $form): void
    {
        $controls = (new SourceControlExtractor())->extract(
            $this->parse($source),
            $source,
            RelativePath::fromString('src/Example.php'),
            [],
            [],
        );

        self::assertCount(1, $controls->suppressions, $carrier);
        self::assertSame(DirectiveRefusalReason::NamesNoTarget, $controls->suppressions[0]->refusal?->reason, $carrier);
        self::assertSame($form, $controls->suppressions[0]->refusal->form, $carrier);
        self::assertFalse($controls->suppressions[0]->matches('complexity.ccn', null), $carrier);
        self::assertFalse(
            $controls->suppressions[0]->target()->appliesToEveryChannel(),
            $carrier,
        );
    }

    /** @return iterable<string, array{non-empty-string, non-empty-string, non-empty-string}> */
    public static function provideChannellessDirectiveCarriers(): iterable
    {
        $onAClass = static fn(string $comment): string => "<?php\ndeclare(strict_types=1);\nnamespace Demo;\n{$comment}\nclass Example { public function m(): int { return 0; } }\n";
        $inABody = static fn(string $comment): string => "<?php\ndeclare(strict_types=1);\nnamespace Demo;\nclass Example { public function m(): int {\n{$comment}\nreturn 0; } }\n";

        yield 'line comment' => [$onAClass('// @qmx-ignore'), 'line comment', 'symbol'];
        yield 'block comment' => [$onAClass('/* @qmx-ignore */'), 'block comment', 'symbol'];
        yield 'docblock on one line' => [$onAClass('/** @qmx-ignore */'), 'docblock on one line', 'symbol'];
        yield 'docblock on its own line' => [$onAClass("/**\n * @qmx-ignore\n */"), 'docblock on its own line', 'symbol'];
        yield 'next-line form in a line comment' => [$inABody('// @qmx-ignore-next-line'), 'next-line line comment', 'next-line'];
        yield 'next-line form in a docblock' => [$inABody('/** @qmx-ignore-next-line */'), 'next-line docblock', 'next-line'];
    }

    /**
     * A threshold is a docblock form, and the other carriers used to be
     * skipped by both extractors: the suppression sweep left the family to
     * its own reader, and that reader searches docblocks only. The form over
     * a measured method therefore retuned nothing and said nothing.
     *
     * @param non-empty-string $comment
     */
    #[Test]
    #[DataProvider('provideThresholdsOutsideADocblock')]
    public function itRefusesAThresholdWrittenOutsideADocblock(string $comment): void
    {
        $controls = self::measuredControls("<?php\nnamespace App;\nclass Named\n{\n    {$comment}\n    public function run(): void {}\n}\n");

        self::assertSame([], $controls->thresholdOverrides, $comment);
        self::assertSame([], $controls->thresholdDiagnostics, $comment);
        self::assertCount(1, $controls->suppressions, $comment);
        self::assertSame(DirectiveRefusalReason::ThresholdOutsideDocblock, $controls->suppressions[0]->refusal?->reason, $comment);
        self::assertSame(DirectiveRefusal::THRESHOLD_FORM, $controls->suppressions[0]->refusal->form, $comment);
        self::assertSame(5, $controls->suppressions[0]->line, $comment);
        self::assertFalse($controls->suppressions[0]->matches('complexity.ccn', null), $comment);
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function provideThresholdsOutsideADocblock(): iterable
    {
        yield 'line comment' => ['// @qmx-threshold complexity.ccn 5'];
        yield 'block comment' => ['/* @qmx-threshold complexity.ccn 5 */'];
    }

    /**
     * The same docblock that retunes a method retunes nothing above a
     * statement or on a property without hooks, and neither place was
     * reported: the threshold reader never visits a statement, and on a
     * property it kept the diagnostics and dropped the override itself.
     *
     * @param non-empty-string $member
     */
    #[Test]
    #[DataProvider('provideThresholdsWithNoDeclarationToRetune')]
    public function itRefusesADocblockThresholdWhereNothingIsMeasured(string $member, int $line): void
    {
        $controls = self::measuredControls("<?php\nnamespace App;\nclass Named\n{\n{$member}\n}\n");

        self::assertSame([], $controls->thresholdOverrides, $member);
        self::assertSame([], $controls->thresholdDiagnostics, $member);
        self::assertCount(1, $controls->suppressions, $member);
        self::assertSame(DirectiveRefusalReason::NoDeclarationToBind, $controls->suppressions[0]->refusal?->reason, $member);
        self::assertSame(DirectiveRefusal::THRESHOLD_FORM, $controls->suppressions[0]->refusal->form, $member);
        self::assertSame($line, $controls->suppressions[0]->line, $member);
    }

    /** @return iterable<string, array{non-empty-string, int}> */
    public static function provideThresholdsWithNoDeclarationToRetune(): iterable
    {
        yield 'above a statement' => [
            "    public function run(int \$a): int\n    {\n        /** @qmx-threshold complexity.ccn 5 */\n        if (\$a > 0) { return 1; }\n        return 0;\n    }",
            7,
        ];
        yield 'on a property without hooks' => [
            "    /** @qmx-threshold complexity.ccn 5 */\n    public int \$value = 0;",
            5,
        ];
    }

    /**
     * The threshold grammar used to cross a line break to find its rule, so
     * a tag with nothing after it took the comment's own delimiter or the
     * next line's leading asterisk as the rule `*` — reported, but as a
     * directive the author never wrote.
     *
     * @param non-empty-string $docblock
     */
    #[Test]
    #[DataProvider('provideThresholdsNamingNoRule')]
    public function itRefusesAThresholdThatNamesNoRule(string $docblock, int $line): void
    {
        $controls = self::measuredControls("<?php\nnamespace App;\nclass Named\n{\n    {$docblock}\n    public function run(): void {}\n}\n");

        self::assertSame([], $controls->thresholdOverrides, $docblock);
        self::assertSame([], $controls->thresholdDiagnostics, $docblock);
        self::assertCount(1, $controls->suppressions, $docblock);
        self::assertSame(DirectiveRefusalReason::NamesNoTarget, $controls->suppressions[0]->refusal?->reason, $docblock);
        self::assertSame(DirectiveRefusal::THRESHOLD_FORM, $controls->suppressions[0]->refusal->form, $docblock);
        self::assertSame($line, $controls->suppressions[0]->line, $docblock);
    }

    /** @return iterable<string, array{non-empty-string, int}> */
    public static function provideThresholdsNamingNoRule(): iterable
    {
        yield 'docblock on one line' => ['/** @qmx-threshold */', 5];
        yield 'docblock on its own line' => ["/**\n     * @qmx-threshold\n     * @return void\n     */", 6];
    }

    /** The legitimate neighbour of every refusal above. */
    #[Test]
    public function itStillAppliesADocblockThresholdOverAMeasuredMethod(): void
    {
        $controls = self::measuredControls("<?php\nnamespace App;\nclass Named\n{\n    /** @qmx-threshold complexity.ccn warning=5 error=9 */\n    public function run(): void {}\n}\n");

        self::assertSame([], $controls->suppressions);
        self::assertSame([], $controls->thresholdDiagnostics);
        self::assertCount(1, $controls->thresholdOverrides);
        self::assertSame('complexity.ccn', $controls->thresholdOverrides[0]->rulePattern);
        self::assertSame(5, $controls->thresholdOverrides[0]->warning);
        self::assertSame(9, $controls->thresholdOverrides[0]->error);
    }

    /**
     * The identity a directive is deduplicated by named its type, rule and
     * line, but not the form a refusal carries — so two different unreadable
     * tags naming one channel on one line collapsed into one, and a refusal
     * could replace another on the same line.
     *
     * @param non-empty-string $comment
     * @param list<string> $forms
     */
    #[Test]
    #[DataProvider('provideTwoRefusalsOnOneLine')]
    public function itKeepsTwoDifferentRefusalsWrittenOnOneLine(string $comment, array $forms): void
    {
        $controls = self::measuredControls("<?php\nnamespace App;\nclass Named\n{\n    public function run(int \$a): int\n    {\n        {$comment}\n        return \$a;\n    }\n}\n");

        $authored = array_map(
            static fn(Suppression $suppression): string => $suppression->refusal->form ?? $suppression->type->value,
            $controls->suppressions,
        );
        sort($authored);

        self::assertSame($forms, $authored, $comment);
    }

    /** @return iterable<string, array{non-empty-string, list<string>}> */
    public static function provideTwoRefusalsOnOneLine(): iterable
    {
        yield 'two unknown tags' => [
            '// @qmx-ignore-lines complexity.ccn @qmx-ignore-lins complexity.ccn',
            ['ignore-lines', 'ignore-lins'],
        ];
        yield 'an unbound declaration form and an unknown tag' => [
            '// @qmx-ignorr complexity.ccn @qmx-ignore complexity.ccn',
            ['ignorr', 'symbol'],
        ];
    }

    /**
     * One refusal authored in a class docblock is materialised once per
     * declaration the docblock binds to; it is still one refusal.
     */
    #[Test]
    public function itCollapsesOneRefusalBoundToEveryDeclarationOfAClass(): void
    {
        $controls = self::measuredControls("<?php\nnamespace App;\n/** @qmx-ignore-lines complexity.ccn */\nclass Named\n{\n    public function a(): void {}\n    public function b(): void {}\n}\n");

        self::assertCount(1, $controls->suppressions);
        self::assertSame('ignore-lines', $controls->suppressions[0]->refusal?->form);
    }

    /**
     * php-parser attaches a comment written between an attribute group and
     * the declaration to no node, so a directive there was neither carried
     * out nor refused — while PHP's own reflection hands the same docblock to
     * the declaration.
     *
     * @param non-empty-string $docblock
     */
    #[Test]
    #[DataProvider('provideDocblocksAfterAnAttribute')]
    public function itAnswersForADirectiveWrittenAfterAnAttribute(string $docblock): void
    {
        $controls = self::measuredControls("<?php\nnamespace App;\nclass Named\n{\n    #[\\Deprecated]\n    {$docblock}\n    public function run(): void {}\n}\n");

        self::assertCount(1, $controls->suppressions, $docblock);
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function provideDocblocksAfterAnAttribute(): iterable
    {
        yield 'a directive' => ['/** @qmx-ignore complexity.ccn -- after the attribute */'];
        yield 'a misspelled tag' => ['/** @qmx-ignorr complexity.ccn -- after the attribute */'];
    }

    /**
     * Where the comment stands relative to the attributes is not supposed to
     * change what it means: each placement after the attributes must read
     * exactly as the same comment written before them, which is the
     * placement php-parser already hands to the declaration.
     *
     * @param non-empty-string $before
     * @param non-empty-string $after
     */
    #[Test]
    #[DataProvider('provideCommentsOnEitherSideOfAnAttribute')]
    public function itReadsACommentAfterTheAttributesAsItReadsOneBeforeThem(string $before, string $after): void
    {
        $expected = self::readable(self::measuredControls($before));

        self::assertNotSame([], $expected);
        self::assertSame($expected, self::readable(self::measuredControls($after)));
    }

    /** @return iterable<string, array{non-empty-string, non-empty-string}> */
    public static function provideCommentsOnEitherSideOfAnAttribute(): iterable
    {
        $class = static fn(string $head, string $body = ''): string => "<?php\nnamespace App;\n{$head}\nclass Named\n{\n{$body}\n    public function run(): void {}\n}\n";
        $member = static fn(string $head, string $declaration): string => $class('', "    {$head}\n    {$declaration}");
        $ignore = '/** @qmx-ignore complexity.ccn -- reason */';

        yield 'docblock on a class' => [$class("{$ignore}\n#[\\Deprecated]"), $class("#[\\Deprecated]\n{$ignore}")];
        yield 'docblock on a method' => [
            $member("{$ignore}\n    #[\\Deprecated]", 'public function other(): void {}'),
            $member("#[\\Deprecated]\n    {$ignore}", 'public function other(): void {}'),
        ];
        yield 'line comment on a method' => [
            $member("// @qmx-ignore complexity.ccn -- reason\n    #[\\Deprecated]", 'public function other(): void {}'),
            $member("#[\\Deprecated]\n    // @qmx-ignore complexity.ccn -- reason", 'public function other(): void {}'),
        ];
        yield 'misspelled tag on a method' => [
            $member("/** @qmx-ignorr complexity.ccn */\n    #[\\Deprecated]", 'public function other(): void {}'),
            $member("#[\\Deprecated]\n    /** @qmx-ignorr complexity.ccn */", 'public function other(): void {}'),
        ];
        yield 'two attribute groups' => [
            $member("{$ignore}\n    #[\\Deprecated]\n    #[\\Override]", 'public function other(): void {}'),
            $member("#[\\Deprecated]\n    #[\\Override]\n    {$ignore}", 'public function other(): void {}'),
        ];
        yield 'docblock on a property' => [
            $member("{$ignore}\n    #[\\Deprecated]", 'public int $value = 1;'),
            $member("#[\\Deprecated]\n    {$ignore}", 'public int $value = 1;'),
        ];
        yield 'docblock on a promoted constructor parameter' => [
            $member('', "public function __construct(\n        {$ignore}\n        #[\\SensitiveParameter] private int \$value,\n    ) {}"),
            $member('', "public function __construct(\n        #[\\SensitiveParameter] {$ignore}\n        private int \$value,\n    ) {}"),
        ];
        yield 'threshold on a method' => [
            $member("/** @qmx-threshold complexity.ccn warning=5 error=9 */\n    #[\\Deprecated]", 'public function other(): void {}'),
            $member("#[\\Deprecated]\n    /** @qmx-threshold complexity.ccn warning=5 error=9 */", 'public function other(): void {}'),
        ];
    }

    /**
     * php-parser gives these two comments to a part of the declaration — the
     * second attribute group, the name — rather than to nothing, so they are
     * not re-homed; they are refused, which is still an answer.
     *
     * @param non-empty-string $method
     */
    #[Test]
    #[DataProvider('provideCommentsGivenToAPartOfTheDeclaration')]
    public function itRefusesADirectiveGivenToAPartOfTheDeclaration(string $method): void
    {
        $controls = self::measuredControls("<?php\nnamespace App;\nclass Named\n{\n{$method}\n}\n");

        self::assertSame(['symbol|complexity.ccn|refused:no-declaration-to-bind'], self::readable($controls));
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function provideCommentsGivenToAPartOfTheDeclaration(): iterable
    {
        yield 'between two attribute groups' => ["    #[\\Deprecated]\n    /** @qmx-ignore complexity.ccn */\n    #[\\Override]\n    public function run(): void {}"];
        yield 'between the keyword and the name' => ["    #[\\Deprecated] public function /** @qmx-ignore complexity.ccn */ run(): void {}"];
    }

    /**
     * A cache hit hands one tree to whoever asks, so extraction reads a
     * comment into the declaration without writing it there.
     */
    #[Test]
    public function itLeavesTheTreeItReadsUnchanged(): void
    {
        $source = "<?php\n#[\\Deprecated]\n/** @qmx-ignorr complexity.ccn */\nfunction run(): void {}\n";
        $ast = $this->parse($source);
        $function = (new NodeFinder())->findFirstInstanceOf($ast, Node\Stmt\Function_::class);
        self::assertInstanceOf(Node\Stmt\Function_::class, $function);
        $extract = static fn(): SourceControls => (new SourceControlExtractor())->extract($ast, $source, RelativePath::fromString('src/Example.php'), [], []);

        $first = $extract();

        self::assertSame([], $function->getComments());
        self::assertCount(1, $first->suppressions);
        self::assertEquals($first, $extract());
    }

    /**
     * Two docblocks, one on each side of the attributes, are two sets of
     * directives an author can see above one declaration, and both are read.
     */
    #[Test]
    public function itReadsDocblocksOnBothSidesOfTheAttributes(): void
    {
        $controls = self::measuredControls("<?php\nnamespace App;\nclass Named\n{\n    /** @qmx-ignore complexity.ccn -- before */\n    #[\\Deprecated]\n    /** @qmx-ignore size.loc -- after */\n    public function run(): void {}\n}\n");

        $read = array_map(static fn(string $entry): array => explode('|', $entry), self::readable($controls));

        self::assertCount(2, $read);
        self::assertSame(['symbol', 'complexity.ccn'], \array_slice($read[0], 0, 2));
        self::assertSame(['symbol', 'size.loc'], \array_slice($read[1], 0, 2));
        self::assertStringContainsString('Named::run', $read[0][2]);
        self::assertSame($read[0][2], $read[1][2]);
    }

    /**
     * A comment php-parser attaches to no node outside an attribute gap has
     * no declaration either. It is read for what it can still mean — a
     * physical form works, a declaration form is refused — instead of being
     * dropped.
     *
     * @param non-empty-string $statement
     * @param non-empty-string $expected
     */
    #[Test]
    #[DataProvider('provideCommentsNoNodeCarries')]
    public function itReadsACommentNoNodeCarries(string $statement, string $expected): void
    {
        $controls = self::measuredControls("<?php\nnamespace App;\nclass Named\n{\n    public function run(int \$a): array\n    {\n{$statement}\n    }\n}\n");

        self::assertSame([$expected], self::readable($controls));
    }

    /** @return iterable<string, array{non-empty-string, non-empty-string}> */
    public static function provideCommentsNoNodeCarries(): iterable
    {
        yield 'a declaration form at the tail of a call' => [
            "        return f(\$a /* @qmx-ignore complexity.ccn */);",
            'symbol|complexity.ccn|refused:no-declaration-to-bind',
        ];
        yield 'a next-line form closing an array' => [
            "        return [\n            \$a,\n            // @qmx-ignore-next-line complexity.ccn\n        ];",
            'next-line|complexity.ccn|',
        ];
    }

    /**
     * What a directive was read as, without the line it was written on: the
     * form, the channel, and either the declaration it binds or the reason it
     * was refused. Threshold overrides and diagnostics are listed by rule and
     * subject the same way.
     *
     * @return list<string>
     */
    private static function readable(SourceControls $controls): array
    {
        $read = [];
        foreach ($controls->suppressions as $suppression) {
            $read[] = implode('|', [
                $suppression->type->value,
                $suppression->rule,
                $suppression->refusal !== null
                    ? 'refused:' . $suppression->refusal->reason->value
                    : ($suppression->binding?->subject->toCanonical() ?? ''),
            ]);
        }

        foreach ($controls->thresholdOverrides as $override) {
            $read[] = 'threshold|' . $override->rulePattern . '|' . $override->subject->toCanonical();
        }

        foreach ($controls->thresholdDiagnostics as $diagnostic) {
            $read[] = 'threshold-diagnostic|' . $diagnostic->subject->toCanonical();
        }

        sort($read);

        return $read;
    }

    /**
     * Extracts with every named class and every method in the source measured.
     *
     * @param non-empty-string $source
     */
    private static function measuredControls(string $source): SourceControls
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $file = RelativePath::fromString('src/Example.php');
        $nodes = new NodeFinder();
        $classes = [];
        $callables = [];

        foreach ($nodes->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
            $className = $class->name?->toString() ?? throw new LogicException('Expected a named class');
            $classDeclaration = DeclarationPath::of(SymbolPath::forClass('App', $className), $file, DeclarationOrdinal::fromRank(0));
            $classMetrics = new ClassWithMetrics($classDeclaration, $class->getStartFilePos(), $class->getStartLine(), new MetricBag());
            $classes[$classMetrics->subject->toCanonical()] = [
                'subject' => $classMetrics->subject,
                'metrics' => $classMetrics->metrics,
                'line' => $classMetrics->line,
                'start' => $classMetrics->startFilePos,
            ];

            foreach ($nodes->findInstanceOf($class->stmts, Node\Stmt\ClassMethod::class) as $method) {
                $callables[] = new CallableWithMetrics(
                    DeclarationPath::of(SymbolPath::forMethod('App', $className, $method->name->toString()), $file, DeclarationOrdinal::fromRank(0)),
                    $method->getStartFilePos(),
                    CallableKind::Method,
                    null,
                    $classDeclaration,
                    new LogicalClassPath(SymbolPath::forClass('App', $className)),
                    new MetricBag(),
                );
            }
        }

        return (new SourceControlExtractor())->extract(array_values($ast), $source, $file, $callables, $classes);
    }

    /** @return list<Node> */
    private function parse(string $source): array
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertIsArray($ast);

        return array_values($ast);
    }
}
