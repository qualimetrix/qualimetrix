<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\FormatterContextFactory;
use Qualimetrix\Reporting\Formatter\FormatOptionValue;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\GroupBy;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(FormatterContextFactory::class)]
final class FormatterContextFactoryTest extends TestCase
{
    private FormatterContextFactory $factory;
    private FormatterInterface $formatter;
    private NullOutput $output;

    protected function setUp(): void
    {
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('declaredFormatOptionKeys')->willReturn(['contributors', 'violations']);
        $this->factory = new FormatterContextFactory($registry);
        $this->formatter = self::createStub(FormatterInterface::class);
        $this->formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $this->output = new NullOutput();
    }

    #[Test]
    public function itSetsTheViolationsOptionToAllWhenAllFlagIsPassed(): void
    {
        $input = $this->createInput(['--all' => true]);

        $context = $this->factory->create($input, $this->output, $this->formatter, $this->projectRoot());

        self::assertSame('all', $context->getOption('violations'));
    }

    #[Test]
    public function itSetsTheDetailLimitToUnlimitedWhenAllFlagIsPassed(): void
    {
        $input = $this->createInput(['--all' => true]);

        $context = $this->factory->create($input, $this->output, $this->formatter, $this->projectRoot());

        self::assertSame(0, $context->detailLimit);
    }

    #[Test]
    public function itAcceptsAllFlagTogetherWithAnEquivalentFormatOptViolationsAll(): void
    {
        $input = $this->createInput([
            '--all' => true,
            '--format-opt' => ['violations=all'],
        ]);

        $context = $this->factory->create($input, $this->output, $this->formatter, $this->projectRoot());

        self::assertSame('all', $context->getOption('violations'));
        self::assertSame(0, $context->detailLimit);
    }

    #[Test]
    public function itThrowsWhenAllFlagConflictsWithANumericViolationsFormatOpt(): void
    {
        $input = $this->createInput([
            '--all' => true,
            '--format-opt' => ['violations=10'],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Conflicting options: --all cannot be combined with --format-opt=violations=N');

        $this->factory->create($input, $this->output, $this->formatter, $this->projectRoot());
    }

    #[Test]
    public function itLetsAllFlagOverrideAnExplicitDetailLimit(): void
    {
        $input = $this->createInput([
            '--all' => true,
            '--detail' => '50',
        ]);

        $context = $this->factory->create($input, $this->output, $this->formatter, $this->projectRoot());

        // --all overrides --detail=50 to unlimited (0)
        self::assertSame(0, $context->detailLimit);
    }

    #[Test]
    public function itLeavesDefaultsUnchangedWhenAllFlagIsAbsent(): void
    {
        $input = $this->createInput([]);

        $context = $this->factory->create($input, $this->output, $this->formatter, $this->projectRoot());

        // No findings option set
        self::assertSame('', $context->getOption('violations'));
        // Detail limit is null (off)
        self::assertNull($context->detailLimit);
    }

    #[Test]
    public function itAcceptsFormatOptViolationsAllWithoutTheAllFlag(): void
    {
        $input = $this->createInput([
            '--format-opt' => ['violations=all'],
        ]);

        $context = $this->factory->create($input, $this->output, $this->formatter, $this->projectRoot());

        self::assertSame('all', $context->getOption('violations'));
    }

    #[Test]
    public function itRefusesAFormatOptKeyNoFormatterReads(): void
    {
        $input = $this->createInput(['--format-opt' => ['zzz=1']]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown --format-opt key "zzz". No formatter reads it. Known keys: contributors, violations.');

        $this->factory->create($input, $this->output, $this->formatter, $this->projectRoot());
    }

    #[Test]
    public function itAcceptsAKeyReadByAnotherFormatterThanTheOneSelected(): void
    {
        $input = $this->createInput(['--format-opt' => ['contributors=3']]);

        $context = $this->factory->create($input, $this->output, $this->formatter, $this->projectRoot());

        self::assertSame('3', $context->getOption('contributors'));
    }

    #[Test]
    public function itBindsAnExplicitNamespaceSelectorOnceAtTheCliBoundary(): void
    {
        $context = $this->factory->create(
            $this->createInput(['--namespace' => 'regex:App\\\\(?:Service|Controller)']),
            $this->output,
            $this->formatter,
            $this->projectRoot(),
        );

        self::assertSame('regex:App\\\\(?:Service|Controller)', $context->namespaceDisplay());
        self::assertTrue($context->namespace?->matches('App\\Service'));
        self::assertSame(200, $context->detailLimit);
    }

    #[Test]
    public function itRefusesABareNamespaceSelector(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must use KIND:VALUE');

        $this->factory->create(
            $this->createInput(['--namespace' => 'App\\Service']),
            $this->output,
            $this->formatter,
            $this->projectRoot(),
        );
    }

    #[Test]
    public function itNamesEveryUnknownKeyAtOnce(): void
    {
        $input = $this->createInput(['--format-opt' => ['zzz=1', 'yyy=2']]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown --format-opt keys "zzz", "yyy". No formatter reads them.');

        $this->factory->create($input, $this->output, $this->formatter, $this->projectRoot());
    }

    /**
     * The key --all writes is checked like any other, so a formatter that stopped
     * declaring `violations` would be caught here rather than leaving --all
     * writing into an array nobody reads.
     */
    #[Test]
    public function itRefusesTheViolationsKeyTheAllFlagWritesWhenNoFormatterDeclaresIt(): void
    {
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('declaredFormatOptionKeys')->willReturn(['contributors']);
        $factory = new FormatterContextFactory($registry);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown --format-opt key "violations"');

        $factory->create(
            $this->createInput(['--all' => true]),
            $this->output,
            $this->formatter,
            $this->projectRoot(),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function provideUnparsableOutputOptionValues(): iterable
    {
        yield 'detail word' => [['--detail' => 'abc'], '--detail', 'Invalid --detail value "abc"'];
        yield 'detail negative' => [['--detail' => '-1'], '--detail', 'Invalid --detail value "-1"'];
        yield 'detail fraction' => [['--detail' => '2.5'], '--detail', 'Invalid --detail value "2.5"'];
        yield 'top word' => [['--top' => 'abc'], '--top', 'Invalid --top value "abc"'];
        yield 'top negative' => [['--top' => '-3'], '--top', 'Invalid --top value "-3"'];
        yield 'violations word' => [['--format-opt' => ['violations=xyz']], '--format-opt', 'Invalid --format-opt value "violations=xyz": expected a whole number, 0 or more, or "all".'];
        yield 'contributors word' => [['--format-opt' => ['contributors=abc']], '--format-opt', 'Invalid --format-opt value "contributors=abc": expected a whole number, 0 or more.'];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[Test]
    #[DataProvider('provideUnparsableOutputOptionValues')]
    public function itRefusesAnUnparsableOutputOptionValueInsteadOfFallingBackToADefault(
        array $parameters,
        string $option,
        string $message,
    ): void {
        try {
            $this->factory->create($this->createInput($parameters), $this->output, $this->formatter, $this->projectRoot());
            self::fail('An unparsable value must be refused, not replaced by a default.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString($message, $refusal->getMessage());
            self::assertSame($option, $refusal->origin()->locator());
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[Test]
    #[DataProvider('provideUnparsableOutputOptionValues')]
    public function itRefusesTheSameValuesBeforeTheAnalysisStarts(array $parameters, string $option, string $message): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage($message);

        $this->factory->bindBeforeAnalysis($this->createInput($parameters));
    }

    /**
     * One grammar per key, whichever format reads it: `top=2.9` used to be 2 in
     * `summary` and the default 10 in `json`.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnparsableFormatOptValuesOfEveryGrammar(): iterable
    {
        yield 'top fraction' => ['top=2.9', 'expected a whole number, 1 or more.'];
        yield 'top zero' => ['top=0', 'expected a whole number, 1 or more.'];
        yield 'limit word' => ['limit=many', 'expected a whole number, 0 or more, or "all".'];
        yield 'rank-by typo' => ['rank-by=dnesity', 'expected one of: count, density.'];
        yield 'empty project name' => ['project-name=', 'expected a non-empty name.'];
        yield 'contributors negative' => ['contributors=-1', 'expected a whole number, 0 or more.'];
    }

    #[Test]
    #[DataProvider('provideUnparsableFormatOptValuesOfEveryGrammar')]
    public function itRefusesAnUnparsableValueOfEveryDeclaredFormatOptKey(string $pair, string $expected): void
    {
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('declaredFormatOptionKeys')->willReturn(FormatOptionValue::keys());
        $factory = new FormatterContextFactory($registry);

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage(\sprintf('Invalid --format-opt value "%s": %s', $pair, $expected));

        $factory->bindBeforeAnalysis($this->createInput(['--format-opt' => [$pair]]));
    }

    #[Test]
    public function itRefusesAMistypedGroupByBeforeTheAnalysisStarts(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Invalid --group-by value "sevrity"');

        $this->factory->bindBeforeAnalysis($this->createInput(['--group-by' => 'sevrity']));
    }

    #[Test]
    public function itRefusesTheUnknownFormatOptKeyBeforeTheAnalysisStarts(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Unknown --format-opt key "zzz"');

        $this->factory->bindBeforeAnalysis($this->createInput(['--format-opt' => ['zzz=1']]));
    }

    /**
     * The legitimate forms beside each refused one still parse to what they meant.
     */
    #[Test]
    public function itStillAcceptsEveryDocumentedFormOfDetailAndTop(): void
    {
        $parse = fn(array $parameters): \Qualimetrix\Reporting\FormatterContext => $this->factory->create(
            $this->createInput($parameters),
            $this->output,
            $this->formatter,
            $this->projectRoot(),
        );

        self::assertNull($parse([])->detailLimit);
        self::assertSame(200, $parse(['--detail' => null])->detailLimit);
        self::assertSame(0, $parse(['--detail' => 'all'])->detailLimit);
        self::assertSame(0, $parse(['--detail' => '0'])->detailLimit);
        self::assertSame(7, $parse(['--detail' => '7'])->detailLimit);
        self::assertSame(10, $parse([])->topIssuesLimit);
        self::assertSame(0, $parse(['--top' => '0'])->topIssuesLimit);
        self::assertSame(3, $parse(['--top' => '3'])->topIssuesLimit);
        self::assertSame('0', $parse(['--format-opt' => ['violations=0']])->getOption('violations'));
        self::assertNull($this->factory->bindBeforeAnalysis($this->createInput(['--detail' => '5', '--top' => '2'])));
    }

    /**
     * An embedder's `ArrayInput` hands over the PHP value it was given, and a
     * number there is the number a command line would have typed: it used to
     * reach a string-typed parser and end the run as an internal error.
     */
    #[Test]
    public function itReadsANumberAnEmbedderPassesAsANumber(): void
    {
        $parse = fn(array $parameters): \Qualimetrix\Reporting\FormatterContext => $this->factory->create(
            $this->createInput($parameters),
            $this->output,
            $this->formatter,
            $this->projectRoot(),
        );

        self::assertSame(50, $parse(['--detail' => 50])->detailLimit);
        self::assertSame(0, $parse(['--detail' => 0])->detailLimit);
        self::assertSame(200, $parse(['--detail' => true])->detailLimit);
        self::assertSame(5, $parse(['--top' => 5])->topIssuesLimit);
        self::assertSame(0, $parse(['--top' => 0])->topIssuesLimit);
        self::assertNull($this->factory->bindBeforeAnalysis($this->createInput(['--detail' => 5, '--top' => 2])));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function provideEmbedderValuesNoCommandLineCouldType(): iterable
    {
        yield 'negative detail' => [['--detail' => -1], '--detail', 'Invalid --detail value "-1"'];
        yield 'negative top' => [['--top' => -3], '--top', 'Invalid --top value "-3"'];
        yield 'numeric group-by' => [['--group-by' => 5], '--group-by', 'Invalid --group-by value "5"'];
        yield 'fractional top' => [['--top' => 2.5], '--top', 'Invalid --top value of type float'];
        yield 'list as detail' => [['--detail' => ['7']], '--detail', 'Invalid --detail value of type array'];
        yield 'numeric format-opt pair' => [['--format-opt' => [5]], '--format-opt', 'Invalid --format-opt value "5": expected format key=value'];
    }

    /**
     * The value is refused as input, never left to a type error that reports
     * the product as broken.
     *
     * @param array<string, mixed> $parameters
     */
    #[Test]
    #[DataProvider('provideEmbedderValuesNoCommandLineCouldType')]
    public function itRefusesAnEmbedderValueOfTheWrongShapeAsInput(array $parameters, string $option, string $message): void
    {
        try {
            $this->factory->bindBeforeAnalysis($this->createInput($parameters));
            self::fail('A value of the wrong shape must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString($message, $refusal->getMessage());
            self::assertSame($option, $refusal->origin()->locator());
        }
    }

    /**
     * A lone string is one pair written through an array input, not a value
     * to iterate: iterating it dropped the pair unread.
     */
    #[Test]
    public function itReadsALoneFormatOptStringAsOnePair(): void
    {
        $context = $this->factory->create(
            $this->createInput(['--format-opt' => 'violations=3']),
            $this->output,
            $this->formatter,
            $this->projectRoot(),
        );

        self::assertSame('3', $context->getOption('violations'));

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Invalid --format-opt value "violations=xyz"');

        $this->factory->bindBeforeAnalysis($this->createInput(['--format-opt' => 'violations=xyz']));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFormatOptPairsAFoldWouldHaveHidden(): iterable
    {
        yield 'unparsable pair overwritten by a valid one' => [
            ['--format-opt' => ['violations=bad', 'violations=1']],
            'Invalid --format-opt value "violations=bad"',
        ];
        yield 'empty pair overwritten by --all' => [
            ['--format-opt' => ['violations='], '--all' => true],
            'Invalid --format-opt value "violations="',
        ];
        yield 'one key written twice' => [
            ['--format-opt' => ['violations=2', 'violations=1']],
            'The --format-opt key "violations" is written twice',
        ];
        yield 'one key written twice with one value' => [
            ['--format-opt' => ['violations=all', 'violations=all']],
            'The --format-opt key "violations" is written twice',
        ];
    }

    /**
     * Every pair is judged as written: folding pairs by key first let a later
     * pair of one key replace an earlier one unjudged, and `--all` replace a
     * written `violations` the same way.
     *
     * @param array<string, mixed> $parameters
     */
    #[Test]
    #[DataProvider('provideFormatOptPairsAFoldWouldHaveHidden')]
    public function itJudgesEveryWrittenFormatOptPairBeforeFoldingThem(array $parameters, string $message): void
    {
        try {
            $this->factory->bindBeforeAnalysis($this->createInput($parameters));
            self::fail('Every written pair must be judged.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString($message, $refusal->getMessage());
            self::assertSame('--format-opt', $refusal->origin()->locator());
        }
    }

    /** The lawful neighbours of a repeated key: two different keys, and `--all` beside its synonym. */
    #[Test]
    public function itStillAcceptsDistinctKeysAndTheAllFlagBesideItsSynonym(): void
    {
        $context = $this->factory->create(
            $this->createInput(['--format-opt' => ['violations=2', 'contributors=4']]),
            $this->output,
            $this->formatter,
            $this->projectRoot(),
        );
        self::assertSame('2', $context->getOption('violations'));
        self::assertSame('4', $context->getOption('contributors'));

        $withAll = $this->factory->create(
            $this->createInput(['--format-opt' => ['violations=all'], '--all' => true]),
            $this->output,
            $this->formatter,
            $this->projectRoot(),
        );
        self::assertSame('all', $withAll->getOption('violations'));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function createInput(array $parameters): ArrayInput
    {
        $definition = new InputDefinition([
            new InputOption('group-by', null, InputOption::VALUE_REQUIRED),
            new InputOption('format-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('detail', null, InputOption::VALUE_OPTIONAL, '', false),
            new InputOption('top', null, InputOption::VALUE_REQUIRED),
            new InputOption('all', null, InputOption::VALUE_NONE),
            new InputOption('namespace', null, InputOption::VALUE_REQUIRED),
            new InputOption('class', null, InputOption::VALUE_REQUIRED),
        ]);

        return new ArrayInput($parameters, $definition);
    }

    private function projectRoot(): AbsolutePath
    {
        return AbsolutePath::fromString((string) getcwd());
    }
}
