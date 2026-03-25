<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\FilteredInputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(FilteredInputDefinition::class)]
final class FilteredInputDefinitionTest extends TestCase
{
    #[Test]
    public function hiddenOptionsAreExcludedFromGetOptions(): void
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
    public function hiddenOptionsStillAccessibleViaHasOption(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('hidden', null, InputOption::VALUE_REQUIRED, 'Hidden option'));
        $definition->setHiddenOptionNames(['hidden']);

        self::assertTrue($definition->hasOption('hidden'));
    }

    #[Test]
    public function hiddenOptionsStillAccessibleViaGetOption(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('hidden', null, InputOption::VALUE_REQUIRED, 'Hidden option'));
        $definition->setHiddenOptionNames(['hidden']);

        $option = $definition->getOption('hidden');
        self::assertSame('hidden', $option->getName());
    }

    #[Test]
    public function hiddenOptionsStillAccessibleViaShortcut(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('hidden', 'x', InputOption::VALUE_REQUIRED, 'Hidden option'));
        $definition->setHiddenOptionNames(['hidden']);

        self::assertTrue($definition->hasShortcut('x'));
        self::assertSame('hidden', $definition->getOptionForShortcut('x')->getName());
    }

    #[Test]
    public function getOptionDefaultsIncludesHiddenOptions(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('visible', null, InputOption::VALUE_REQUIRED, 'Visible', 'v'));
        $definition->addOption(new InputOption('hidden', null, InputOption::VALUE_REQUIRED, 'Hidden', 'h'));
        $definition->setHiddenOptionNames(['hidden']);

        $defaults = $definition->getOptionDefaults();

        self::assertSame(['visible' => 'v', 'hidden' => 'h'], $defaults);
    }

    #[Test]
    public function synopsisExcludesHiddenOptions(): void
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
    public function noHiddenOptionsReturnsAllOptions(): void
    {
        $definition = new FilteredInputDefinition();
        $definition->addOption(new InputOption('one', null, InputOption::VALUE_NONE, 'One'));
        $definition->addOption(new InputOption('two', null, InputOption::VALUE_NONE, 'Two'));
        $definition->setHiddenOptionNames([]);

        self::assertCount(2, $definition->getOptions());
    }
}
