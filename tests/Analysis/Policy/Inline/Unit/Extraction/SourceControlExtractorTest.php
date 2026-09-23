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
        $ast = $this->parse(<<<'PHP'
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
            PHP);
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
        $ast = $this->parse("<?php\n/** @qmx-ignore complexity.ccn collision */\nfunction run(int \$value): void {}\n");
        $function = (new NodeFinder())->findFirstInstanceOf($ast, Node\Stmt\Function_::class);
        self::assertInstanceOf(Node\Stmt\Function_::class, $function);

        $file = RelativePath::fromString('src/Example.php');
        $first = DeclarationPath::of(SymbolPath::forGlobalFunction('App', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of(SymbolPath::forGlobalFunction('App', 'run'), $file, DeclarationOrdinal::fromRank(1));

        $this->expectException(LogicException::class);
        (new SourceControlExtractor())->extract(
            $ast,
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
        $controls = $extractor->extract(
            $this->parse("<?php\n// @qmx-ignore-file complexity\nfinal class Example {}\n"),
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
        $controls = (new SourceControlExtractor())->extract(
            $this->parse("<?php\ndeclare(strict_types=1);\nnamespace Demo;\nclass Example {\n    public function m(int \$a): int {\n        // @qmx-ignore complexity.ccn -- on a statement\n        if (\$a > 0) { return 1; }\n        return 0;\n    }\n}\n"),
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
    public function itRefusesAChannellessDirectiveInEveryCommentCarrier(string $source, string $carrier): void
    {
        $controls = (new SourceControlExtractor())->extract(
            $this->parse($source),
            RelativePath::fromString('src/Example.php'),
            [],
            [],
        );

        self::assertCount(1, $controls->suppressions, $carrier);
        self::assertSame(
            DirectiveRefusalReason::FormNotRecognised,
            $controls->suppressions[0]->refusal?->reason,
            $carrier,
        );
        self::assertFalse($controls->suppressions[0]->matches('complexity.ccn', null), $carrier);
        self::assertFalse(
            $controls->suppressions[0]->target()->appliesToEveryChannel(),
            $carrier,
        );
    }

    /** @return iterable<string, array{non-empty-string, non-empty-string}> */
    public static function provideChannellessDirectiveCarriers(): iterable
    {
        $onAClass = static fn(string $comment): string => "<?php\ndeclare(strict_types=1);\nnamespace Demo;\n{$comment}\nclass Example { public function m(): int { return 0; } }\n";

        yield 'line comment' => [$onAClass('// @qmx-ignore'), 'line comment'];
        yield 'block comment' => [$onAClass('/* @qmx-ignore */'), 'block comment'];
        yield 'docblock on one line' => [$onAClass('/** @qmx-ignore */'), 'docblock on one line'];
        yield 'docblock on its own line' => [$onAClass("/**\n * @qmx-ignore\n */"), 'docblock on its own line'];
    }

    /** @return list<Node> */
    private function parse(string $source): array
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertIsArray($ast);

        return array_values($ast);
    }
}
