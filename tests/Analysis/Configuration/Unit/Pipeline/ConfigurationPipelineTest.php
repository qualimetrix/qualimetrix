<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(ConfigurationPipeline::class)]
final class ConfigurationPipelineTest extends TestCase
{
    #[Test]
    public function itReturnsAnEmptyDocumentWithTheInvocationDirectoryWhenThereAreNoStages(): void
    {
        $root = AbsolutePath::fromString('/project');
        $document = (new ConfigurationPipeline())->resolve(new ConfigurationResolutionRequest($root));

        self::assertSame($root, $document->workingDirectory());
        self::assertSame([], $document->appliedSources());
    }

    #[Test]
    public function itSortsStagesByPriority(): void
    {
        $pipeline = new ConfigurationPipeline();
        $late = $this->stage(30, 'late', ['format' => 'json']);
        $early = $this->stage(10, 'early', ['format' => 'text']);
        $pipeline->addStage($late);
        $pipeline->addStage($early);

        self::assertSame([$early, $late], $pipeline->stages());
    }

    #[Test]
    public function itRetainsOrderedContributionsInsteadOfApplyingFeatureMergeSemantics(): void
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage($this->stage(20, 'config', [
            'rules' => ['size.loc' => ['warning' => 1000]],
            'disabled_rules' => ['security'],
        ]));
        $pipeline->addStage($this->stage(30, 'cli', [
            'rules' => ['size.loc' => ['error' => 2000]],
            'disabled_rules' => ['design'],
        ]));

        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        self::assertSame([
            ['size.loc' => ['warning' => 1000]],
            ['size.loc' => ['error' => 2000]],
        ], $document->contributions('rules'));
        self::assertSame([['security'], ['design']], $document->contributions('disabled_rules'));
        self::assertSame(['config', 'cli'], $document->appliedSources());
    }

    #[Test]
    public function itSkipsStagesThatDoNotContribute(): void
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage($this->stage(10, 'empty', null));

        self::assertSame([], $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')))->appliedSources());
    }

    #[Test]
    public function itExpandsMultiDocumentLayersWithoutCollapsingThem(): void
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage($this->stage(15, 'preset:strict,ci', [], [
            ['format' => 'text'],
            ['format' => 'json'],
        ]));

        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        self::assertSame(['text', 'json'], $document->contributions('format'));
        self::assertSame(['preset:strict,ci'], $document->appliedSources());
    }

    /**
     * @param array<string, mixed>|null $values
     * @param list<array<string, mixed>> $documents
     */
    private function stage(
        int $priority,
        string $name,
        ?array $values,
        array $documents = [],
    ): ConfigurationStageInterface {
        return new class ($priority, $name, $values, $documents) implements ConfigurationStageInterface {
            /**
             * @param array<string, mixed>|null $values
             * @param list<array<string, mixed>> $documents
             */
            public function __construct(
                private readonly int $stagePriority,
                private readonly string $stageName,
                private readonly ?array $values,
                private readonly array $documents,
            ) {}

            public function priority(): int
            {
                return $this->stagePriority;
            }

            public function name(): string
            {
                return $this->stageName;
            }

            public function apply(ConfigurationResolutionRequest $request): ?ConfigurationLayer
            {
                if ($this->values === null) {
                    return null;
                }

                return new ConfigurationLayer($this->stageName, $this->values, $this->documents);
            }
        };
    }
}
