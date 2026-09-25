<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
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
     * The engine runs beside the contributions: the layers stages hand over as
     * written are composed against the registered sections, in stage order,
     * while `contributions()` still answers what it answered before.
     */
    #[Test]
    public function itComposesTheAuthoredLayersBesideTheContributions(): void
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addSection(new class implements DocumentSectionSchemaInterface {
            public function key(): string
            {
                return 'fail_on';
            }

            public function schema(): NodeSchema
            {
                return NodeSchema::scalar(ScalarForm::String);
            }
        });
        $pipeline->addStage($this->stage(20, 'qmx.yaml', ['fail_on' => 'warning'], [], [
            new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/project/qmx.yaml'), AuthoredNode::fromPlain(['failOn' => 'warning'])),
        ]));
        $pipeline->addStage($this->stage(15, 'preset:strict', [], [['fail_on' => 'error']], [
            new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, 'strict'), AuthoredNode::fromPlain(['fail_on' => 'error', 'legacy' => 1])),
        ]));

        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        self::assertSame(['error', 'warning'], $document->contributions('fail_on'));
        $failOn = $document->resolved()->get('fail_on');
        self::assertNotNull($failOn);
        self::assertSame('warning', $failOn->plain());
        self::assertSame(['/project/qmx.yaml'], array_map(
            static fn(Provenance $writer): ?string => $writer->origin->locator(),
            $failOn->contributors(),
        ));
        self::assertSame([1], $document->resolved()->get('legacy')?->plain(), 'A root no section declares yet passes through.');
    }

    #[Test]
    public function itResolvesAnEmptyDocumentWhenNoStageHandsOverAWrittenLayer(): void
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage($this->stage(20, 'qmx.yaml', ['fail_on' => 'warning']));

        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        self::assertSame([], $document->resolved()->roots());
        self::assertSame([], $document->diagnostics());
    }

    /**
     * @param array<string, mixed>|null $values
     * @param list<array<string, mixed>> $documents
     * @param list<AuthoredLayer> $authored
     */
    private function stage(
        int $priority,
        string $name,
        ?array $values,
        array $documents = [],
        array $authored = [],
    ): ConfigurationStageInterface {
        return new class ($priority, $name, $values, $documents, $authored) implements ConfigurationStageInterface {
            /**
             * @param array<string, mixed>|null $values
             * @param list<array<string, mixed>> $documents
             * @param list<AuthoredLayer> $authored
             */
            public function __construct(
                private readonly int $stagePriority,
                private readonly string $stageName,
                private readonly ?array $values,
                private readonly array $documents,
                private readonly array $authored,
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

                return new ConfigurationLayer($this->stageName, $this->values, $this->documents, $this->authored);
            }
        };
    }
}
