<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\CommandLinePathWrite;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(CliStage::class)]
final class CliStageTest extends TestCase
{
    #[Test]
    public function itHasTheHighestSourcePriority(): void
    {
        self::assertSame(30, (new CliStage())->priority());
        self::assertSame('cli', (new CliStage())->name());
    }

    #[Test]
    public function itReturnsNullWithoutNormalizedOverrides(): void
    {
        self::assertNull((new CliStage())->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'))));
    }

    #[Test]
    public function itPublishesAlreadyNormalizedOverridesWithoutSymfonyInput(): void
    {
        $overrides = [
            'paths' => ['src', 'lib'],
            'cache.enabled' => false,
            'parallel.workers' => 0,
            'format' => 'json',
        ];

        $layer = (new CliStage())->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), null, [], $overrides));

        self::assertNotNull($layer);
        self::assertSame('cli', $layer->source);
        self::assertSame($overrides, $layer->values);
    }

    /**
     * The command line has no key paths: each value reaches the engine under
     * its document path, named by the option that wrote it.
     */
    #[Test]
    public function itHandsTheEngineAnUnpositionedLayerNamedByOption(): void
    {
        $layer = (new CliStage())->apply(new ConfigurationResolutionRequest(
            AbsolutePath::fromString('/project'),
            cliValues: ['cache.dir' => '/tmp/c', 'cache.enabled' => false, 'excludes' => [['subtree' => 'build']]],
            cliOptionNames: ['cache.dir' => '--cache-dir', 'cache.enabled' => '--no-cache', 'excludes' => '--exclude'],
        ));

        self::assertNotNull($layer);
        self::assertCount(1, $layer->authored);
        $written = $layer->authored[0];
        self::assertFalse($written->positioned);
        self::assertSame(ConfigurationSource::CommandLine, $written->origin->source());
        self::assertSame(['cache' => ['dir' => '/tmp/c', 'enabled' => false], 'exclude' => [['subtree' => 'build']]], $written->root->plain());
        self::assertSame('--no-cache', $written->root->children['cache']->children['enabled']->locator);
        self::assertSame('--exclude', $written->root->children['exclude']->locator);
    }

    #[Test]
    public function itContributesOneAuthoredRulesTreeOnlyWhenRuleFlagsWereWritten(): void
    {
        $stage = new CliStage();
        $graph = $stage->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), cliValues: ['paths' => ['src']]));
        self::assertNotNull($graph);
        self::assertArrayNotHasKey('rules', $graph->values);

        $rules = $stage->apply(new ConfigurationResolutionRequest(
            AbsolutePath::fromString('/project'),
            cliPathWrites: [new CommandLinePathWrite(['rules', 'complexity.ccn', 'callable', 'warning'], '10', '--cyclomatic-warning', NodeSchema::scalar(ScalarForm::Integer))],
        ));
        self::assertNotNull($rules);
        self::assertArrayNotHasKey('rules', $rules->values);
        self::assertSame(['complexity.ccn' => ['callable' => ['warning' => 10]]], $rules->authored[0]->root->children['rules']->plain());
    }
}
