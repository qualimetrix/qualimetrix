<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\FormatterContextFactory;
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
