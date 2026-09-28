<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedOpaqueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
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
    public function itRetainsFindingsOrderedRawInputsInsteadOfApplyingFeatureMergeSemantics(): void
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
        ], $document->ruleContributions());
        self::assertSame([['security'], ['design']], $document->disabledRuleContributions());
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
        ], [
            self::preset(['format' => 'text']),
            new AuthoredLayer(
                ConfigurationOrigin::of(ConfigurationSource::Preset, 'ci'),
                AuthoredNode::fromPlain(['format' => 'json']),
            ),
        ]));

        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        self::assertSame('json', $document->resolved()->get('format')?->plain());
        self::assertSame(['preset:strict,ci'], $document->appliedSources());
    }

    /**
     * The engine composes the layers stages hand over as written against
     * Configuration's roots and the sections owners register, in stage order.
     */
    #[Test]
    public function itComposesTheAuthoredLayers(): void
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addSection(self::couplingSection());
        $pipeline->addStage($this->stage(20, 'qmx.yaml', ['fail_on' => 'warning'], [], [
            self::file(['failOn' => 'warning', 'coupling' => ['frameworkNamespaces' => ['App']]]),
        ]));
        $pipeline->addStage($this->stage(15, 'preset:strict', [], [['fail_on' => 'error']], [
            self::preset(['fail_on' => 'error', 'rules' => ['size.loc' => false]]),
        ]));

        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        $failOn = $document->resolved()->get('fail_on');
        self::assertNotNull($failOn);
        self::assertSame('warning', $failOn->plain());
        self::assertSame(['/project/qmx.yaml'], array_map(
            static fn(Provenance $writer): ?string => $writer->origin->locator(),
            $failOn->contributors(),
        ));
        self::assertSame(['App'], $document->resolved()->get('coupling', 'framework_namespaces')?->plain(), 'An owner-registered section is read.');
        $rules = $document->resolved()->get('rules');
        self::assertInstanceOf(ResolvedOpaqueInterface::class, $rules, 'A known root no section declares yet is carried unread.');
        self::assertSame([['size.loc' => false]], $rules->plain());
        self::assertSame('strict', $rules->contributors()[0]->origin->locator());
    }

    /**
     * The root dictionary stays closed while owners declare their sections
     * one by one: a key that is no root at all is refused, `~` or not.
     */
    #[Test]
    public function itRefusesARootNoOneKnowsEvenWhenWrittenNull(): void
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage($this->stage(20, 'qmx.yaml', [], [], [self::file(['fail_onn' => null])]));

        try {
            $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));
            self::fail('An unknown root must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Unknown key "fail_onn"', $refusal->summary());
            self::assertStringContainsString('did you mean "fail_on"?', $refusal->summary());
            self::assertSame(['fail_onn'], $refusal->position()?->segments);
        }
    }

    /**
     * A refusal of the folded values a stage deferred is raised once the
     * engine accepted every layer — and never ahead of the engine's own.
     */
    #[Test]
    public function itRaisesADeferredRefusalOnlyAfterTheEngineJudgedEveryLayer(): void
    {
        $deferred = ConfigurationRefusal::aboutConfigFileDocument('/project/qmx.yaml', 'the folded values are refused');

        $accepted = new ConfigurationPipeline();
        $accepted->addStage($this->stage(20, 'qmx.yaml', [], [], [self::file(['fail_on' => 'error'])], [$deferred]));

        try {
            $accepted->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));
            self::fail('A deferred refusal must still be raised.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame($deferred, $refusal);
        }

        $refused = new ConfigurationPipeline();
        $refused->addStage($this->stage(20, 'qmx.yaml', [], [], [self::file(['Fail_On' => 'error'])], [$deferred]));

        try {
            $refused->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));
            self::fail('The written document must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertNotSame($deferred, $refusal);
            self::assertStringContainsString('write "fail_on"', $refusal->summary());
        }
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
     * @param list<ConfigurationRefusal> $deferred
     */
    private function stage(
        int $priority,
        string $name,
        ?array $values,
        array $documents = [],
        array $authored = [],
        array $deferred = [],
    ): ConfigurationStageInterface {
        return new class ($priority, $name, $values, $documents, $authored, $deferred) implements ConfigurationStageInterface {
            /**
             * @param array<string, mixed>|null $values
             * @param list<array<string, mixed>> $documents
             * @param list<AuthoredLayer> $authored
             * @param list<ConfigurationRefusal> $deferred
             */
            public function __construct(
                private readonly int $stagePriority,
                private readonly string $stageName,
                private readonly ?array $values,
                private readonly array $documents,
                private readonly array $authored,
                private readonly array $deferred,
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

                return new ConfigurationLayer($this->stageName, $this->values, $this->documents, $this->authored, $this->deferred);
            }
        };
    }

    /** @param array<string, mixed> $written */
    private static function file(array $written): AuthoredLayer
    {
        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/project/qmx.yaml'), AuthoredNode::fromPlain($written));
    }

    /** @param array<string, mixed> $written */
    private static function preset(array $written): AuthoredLayer
    {
        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, 'strict'), AuthoredNode::fromPlain($written));
    }

    private static function couplingSection(): DocumentSectionSchemaInterface
    {
        return new class implements DocumentSectionSchemaInterface {
            public function key(): string
            {
                return 'coupling';
            }

            public function schema(): NodeSchema
            {
                return NodeSchema::map(['framework_namespaces' => NodeSchema::stringList()]);
            }
        };
    }
}
