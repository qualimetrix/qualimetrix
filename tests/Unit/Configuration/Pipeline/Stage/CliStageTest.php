<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\Stage\CliStage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(CliStage::class)]
final class CliStageTest extends TestCase
{
    private CliStage $stage;

    protected function setUp(): void
    {
        $this->stage = new CliStage();
    }

    #[Test]
    public function hasPriorityThirty(): void
    {
        self::assertSame(30, $this->stage->priority());
    }

    #[Test]
    public function hasNameCli(): void
    {
        self::assertSame('cli', $this->stage->name());
    }

    #[Test]
    public function returnsNullWhenNoOptionsProvided(): void
    {
        $input = new ArrayInput([]);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNull($layer);
    }

    #[Test]
    public function extractsPathsFromArgument(): void
    {
        $definition = new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY),
        ]);
        $input = new ArrayInput(['paths' => ['src', 'lib']], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('cli', $layer->source);
        self::assertSame(['src', 'lib'], $layer->values['paths']);
    }

    #[Test]
    public function extractsFormatOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('format', null, InputOption::VALUE_REQUIRED),
        ]);
        $input = new ArrayInput(['--format' => 'json'], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('json', $layer->values['format']);
    }

    #[Test]
    public function extractsNoCacheOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('no-cache', null, InputOption::VALUE_NONE),
        ]);
        $input = new ArrayInput(['--no-cache' => true], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNotNull($layer);
        self::assertFalse($layer->values['cache.enabled']);
    }

    #[Test]
    public function extractsCacheDirOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('cache-dir', null, InputOption::VALUE_REQUIRED),
        ]);
        $input = new ArrayInput(['--cache-dir' => '/custom/cache'], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('/custom/cache', $layer->values['cache.dir']);
    }

    #[Test]
    public function extractsExcludeOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED),
        ]);
        $input = new ArrayInput(['--exclude' => ['vendor', 'tests']], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame(['vendor', 'tests'], $layer->values['excludes']);
    }

    #[Test]
    public function extractsDisabledRulesOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('disable-rule', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED),
        ]);
        $input = new ArrayInput(['--disable-rule' => ['complexity', 'size']], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame(['complexity', 'size'], $layer->values['disabled_rules']);
    }

    #[Test]
    public function extractsOnlyRulesOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('only-rule', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED),
        ]);
        $input = new ArrayInput(['--only-rule' => ['complexity', 'size']], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame(['complexity', 'size'], $layer->values['only_rules']);
    }

    #[Test]
    public function ignoresEmptyPathsArray(): void
    {
        $definition = new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY),
        ]);
        $input = new ArrayInput(['paths' => []], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNull($layer);
    }

    #[Test]
    public function ignoresEmptyExcludesArray(): void
    {
        $definition = new InputDefinition([
            new InputOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED),
        ]);
        $input = new ArrayInput(['--exclude' => []], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNull($layer);
    }

    #[Test]
    public function ignoresEmptyFormatString(): void
    {
        $definition = new InputDefinition([
            new InputOption('format', null, InputOption::VALUE_REQUIRED),
        ]);
        $input = new ArrayInput(['--format' => ''], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNull($layer);
    }

    #[Test]
    public function extractsFailOnOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('fail-on', null, InputOption::VALUE_REQUIRED),
        ]);
        $input = new ArrayInput(['--fail-on' => 'error'], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('error', $layer->values['fail_on']);
    }

    #[Test]
    public function combinesMultipleOptions(): void
    {
        $definition = new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY),
            new InputOption('format', null, InputOption::VALUE_REQUIRED),
            new InputOption('no-cache', null, InputOption::VALUE_NONE),
        ]);
        $input = new ArrayInput([
            'paths' => ['src'],
            '--format' => 'json',
            '--no-cache' => true,
        ], $definition);
        $context = new ConfigurationContext($input, '/tmp');

        $layer = $this->stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('cli', $layer->source);
        self::assertSame(['src'], $layer->values['paths']);
        self::assertSame('json', $layer->values['format']);
        self::assertFalse($layer->values['cache.enabled']);
    }
}
