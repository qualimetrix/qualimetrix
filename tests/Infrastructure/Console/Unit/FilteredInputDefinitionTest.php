<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\FilteredInputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(FilteredInputDefinition::class)]
final class FilteredInputDefinitionTest extends TestCase
{
    #[Test]
    public function itExcludesHiddenOptionsFromGetOptions(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('visible', null, InputOption::VALUE_REQUIRED, 'Visible option'));
        $definition->addOption(new InputOption('hidden', null, InputOption::VALUE_REQUIRED, 'Hidden option'));
        $definition->setHiddenOptionNames(['hidden']);

        $options = $definition->getOptions();

        self::assertCount(1, $options);
        self::assertArrayHasKey('visible', $options);
        self::assertArrayNotHasKey('hidden', $options);
    }

    #[Test]
    public function itKeepsHiddenOptionsAccessibleViaHasOption(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('hidden', null, InputOption::VALUE_REQUIRED, 'Hidden option'));
        $definition->setHiddenOptionNames(['hidden']);

        self::assertTrue($definition->hasOption('hidden'));
    }

    #[Test]
    public function itKeepsHiddenOptionsAccessibleViaGetOption(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('hidden', null, InputOption::VALUE_REQUIRED, 'Hidden option'));
        $definition->setHiddenOptionNames(['hidden']);

        $option = $definition->getOption('hidden');
        self::assertSame('hidden', $option->getName());
    }

    #[Test]
    public function itKeepsHiddenOptionsAccessibleViaTheirShortcut(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('hidden', 'x', InputOption::VALUE_REQUIRED, 'Hidden option'));
        $definition->setHiddenOptionNames(['hidden']);

        self::assertTrue($definition->hasShortcut('x'));
        self::assertSame('hidden', $definition->getOptionForShortcut('x')->getName());
    }

    #[Test]
    public function itIncludesHiddenOptionsInGetOptionDefaults(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('visible', null, InputOption::VALUE_REQUIRED, 'Visible', 'v'));
        $definition->addOption(new InputOption('hidden', null, InputOption::VALUE_REQUIRED, 'Hidden', 'h'));
        $definition->setHiddenOptionNames(['hidden']);

        $defaults = $definition->getOptionDefaults();

        self::assertSame(['visible' => 'v', 'hidden' => 'h'], $defaults);
    }

    #[Test]
    public function itExcludesHiddenOptionsFromTheSynopsis(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('visible', null, InputOption::VALUE_NONE, 'Visible'));
        $definition->addOption(new InputOption('hidden', null, InputOption::VALUE_NONE, 'Hidden'));
        $definition->setHiddenOptionNames(['hidden']);

        $synopsis = $definition->getSynopsis();

        self::assertStringContainsString('--visible', $synopsis);
        self::assertStringNotContainsString('--hidden', $synopsis);
    }

    #[Test]
    public function itReturnsEveryOptionWhenNoneAreHidden(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('one', null, InputOption::VALUE_NONE, 'One'));
        $definition->addOption(new InputOption('two', null, InputOption::VALUE_NONE, 'Two'));
        $definition->setHiddenOptionNames([]);

        self::assertCount(2, $definition->getOptions());
    }
}
