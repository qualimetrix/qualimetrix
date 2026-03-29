<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\FormatterContextFactory;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
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
        $this->factory = new FormatterContextFactory();
        $this->formatter = $this->createStub(FormatterInterface::class);
        $this->formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $this->output = new NullOutput();
    }

    #[Test]
    public function allFlagSetsViolationsOptionToAll(): void
    {
        $input = $this->createInput(['--all' => true]);

        $context = $this->factory->create($input, $this->output, $this->formatter);

        self::assertSame('all', $context->getOption('violations'));
    }

    #[Test]
    public function allFlagSetsDetailLimitToUnlimited(): void
    {
        $input = $this->createInput(['--all' => true]);

        $context = $this->factory->create($input, $this->output, $this->formatter);

        self::assertSame(0, $context->detailLimit);
    }

    #[Test]
    public function allFlagWithFormatOptViolationsAllDoesNotConflict(): void
    {
        $input = $this->createInput([
            '--all' => true,
            '--format-opt' => ['violations=all'],
        ]);

        $context = $this->factory->create($input, $this->output, $this->formatter);

        self::assertSame('all', $context->getOption('violations'));
        self::assertSame(0, $context->detailLimit);
    }

    #[Test]
    public function allFlagWithNumericViolationsThrowsException(): void
    {
        $input = $this->createInput([
            '--all' => true,
            '--format-opt' => ['violations=10'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting options: --all cannot be combined with --format-opt=violations=N');

        $this->factory->create($input, $this->output, $this->formatter);
    }

    #[Test]
    public function allFlagOverridesExplicitDetailLimit(): void
    {
        $input = $this->createInput([
            '--all' => true,
            '--detail' => '50',
        ]);

        $context = $this->factory->create($input, $this->output, $this->formatter);

        // --all overrides --detail=50 to unlimited (0)
        self::assertSame(0, $context->detailLimit);
    }

    #[Test]
    public function withoutAllFlagBehaviorIsUnchanged(): void
    {
        $input = $this->createInput([]);

        $context = $this->factory->create($input, $this->output, $this->formatter);

        // No violations option set
        self::assertSame('', $context->getOption('violations'));
        // Detail limit is null (off)
        self::assertNull($context->detailLimit);
    }

    #[Test]
    public function formatOptViolationsAllWithoutAllFlagStillWorks(): void
    {
        $input = $this->createInput([
            '--format-opt' => ['violations=all'],
        ]);

        $context = $this->factory->create($input, $this->output, $this->formatter);

        self::assertSame('all', $context->getOption('violations'));
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
}
