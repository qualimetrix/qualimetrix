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
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
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
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

#[CoversClass(ConfigurationPipeline::class)]
final class ConfigurationPipelineTest extends TestCase
{
    #[Test]
    public function itReturnsAnEmptyDocumentWithTheInvocationDirectoryWhenThereAreNoStages(): void
    {
        $root = AbsolutePath::fromString('/project');
        $document = (new ConfigurationPipeline(LayeredDocument::standaloneSections()))->resolve(new ConfigurationResolutionRequest($root));

        self::assertSame($root, $document->workingDirectory());
        self::assertSame([], $document->appliedSources());
    }

    #[Test]
    public function itSortsStagesByPriority(): void
    {
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $late = $this->stage(30, 'late', ['format' => 'json']);
        $early = $this->stage(10, 'early', ['format' => 'text']);
        $pipeline->addStage($late);
        $pipeline->addStage($early);

        self::assertSame([$early, $late], $pipeline->stages());
    }

    #[Test]
    public function itPreservesTypedValuesAndEveryAuthoredListWriteInStageOrder(): void
    {
        $original = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $original->addStage($this->stage(20, 'config', [], [self::file(['rules' => ['size.loc' => ['warning' => 1000]], 'disabled_rules' => ['security']])]));
        $original->addStage($this->stage(30, 'cli', [], [new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--rule-opt'), AuthoredNode::fromPlain(['rules' => ['size.loc' => ['error' => 2000]], 'disabled_rules' => ['design']]))]));
        try {
            $original->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));
            self::fail('An undeclared producer must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Rule option owner "size.loc" does not match any registered producer rule.', $refusal->summary());
            self::assertSame('/project/qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['rules', 'size.loc'], $refusal->position()?->segments);
            self::assertSame('size.loc', $refusal->position()->written);
        }
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $pipeline->addStage($this->stage(20, 'config', [], [self::file(['rules' => ['size.method-count' => ['warning' => 1000]], 'disabled_rules' => ['security']])]));
        $pipeline->addStage($this->stage(30, 'cli', [], [new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--rule-opt'), AuthoredNode::fromPlain(['rules' => ['size.method-count' => ['error' => 2000]], 'disabled_rules' => ['design']]))]));
        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));
        $warning = $document->resolved()->get('rules', 'size.method-count', 'warning');
        $error = $document->resolved()->get('rules', 'size.method-count', 'error');
        self::assertNotNull($warning);
        self::assertNotNull($error);
        self::assertSame(1000, $warning->plain());
        self::assertSame(2000, $error->plain());
        self::assertSame('/project/qmx.yaml', $warning->contributors()[0]->origin->locator());
        self::assertSame('--rule-opt', $error->contributors()[0]->origin->locator());
        self::assertSame([0, 1], [$warning->contributors()[0]->layerIndex, $error->contributors()[0]->layerIndex]);
        $disabled = $document->resolved()->get('disabled_rules');
        self::assertInstanceOf(\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface::class, $disabled);
        self::assertSame(['security', 'design'], $disabled->plain());
        self::assertSame([['security'], ['design']], array_map(static fn(array $write): mixed => $write['value'], $disabled->writes()));
        self::assertSame(['config', 'cli'], $document->appliedSources());
    }

    #[Test]
    public function itSkipsStagesThatDoNotContribute(): void
    {
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $pipeline->addStage($this->stage(10, 'empty', null));

        self::assertSame([], $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')))->appliedSources());
    }

    #[Test]
    public function itExpandsMultiDocumentLayersWithoutCollapsingThem(): void
    {
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $pipeline->addStage($this->stage(15, 'preset:strict,ci', [], [
            self::preset(['fail_on' => 'warning']),
            new AuthoredLayer(
                ConfigurationOrigin::of(ConfigurationSource::Preset, 'ci'),
                AuthoredNode::fromPlain(['fail_on' => 'error']),
            ),
        ]));

        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        self::assertSame('error', $document->resolved()->get('fail_on')?->plain());
        self::assertSame(['preset:strict,ci'], $document->appliedSources());
    }

    /**
     * The engine composes the layers stages hand over as written against
     * Configuration's roots and the sections owners register, in stage order.
     */
    #[Test]
    public function itComposesTheAuthoredLayers(): void
    {
        $original = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $original->addStage($this->stage(15, 'preset:strict', [], [self::preset(['fail_on' => 'error', 'rules' => ['size.loc' => false]])]));
        try {
            $original->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));
            self::fail('The ordinary rules owner must judge its producers.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Rule option owner "size.loc" does not match any registered producer rule.', $refusal->summary());
            self::assertSame('strict', $refusal->sources()[0]->locator());
            self::assertSame(['rules', 'size.loc'], $refusal->position()?->segments);
            self::assertSame('size.loc', $refusal->position()->written);
        }
        $pipeline = new ConfigurationPipeline(array_values(array_filter(LayeredDocument::standaloneSections(), static fn(DocumentSectionSchemaInterface $section): bool => !\in_array($section->declaration()->key, ['coupling', 'rules'], true))));
        $pipeline->addSection(self::couplingSection());
        $pipeline->addSection(new class implements DocumentSectionSchemaInterface {
            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration('rules', NodeSchema::opaque());
            }
        });
        $pipeline->addStage($this->stage(20, 'qmx.yaml', ['fail_on' => 'warning'], [self::file(['failOn' => 'warning', 'coupling' => ['frameworkNamespaces' => ['App']]])]));
        $pipeline->addStage($this->stage(15, 'preset:strict', [], [self::preset(['fail_on' => 'error', 'rules' => ['size.loc' => false]])]));
        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));
        $failOn = $document->resolved()->get('fail_on');
        self::assertNotNull($failOn);
        self::assertSame('warning', $failOn->plain());
        self::assertSame(['/project/qmx.yaml'], array_map(static fn(Provenance $writer): ?string => $writer->origin->locator(), $failOn->contributors()));
        self::assertSame(['App'], $document->resolved()->get('coupling', 'framework_namespaces')?->plain(), 'An owner-registered section is read.');
        $rules = $document->resolved()->get('rules');
        self::assertInstanceOf(ResolvedOpaqueInterface::class, $rules, 'The fixture declares its opaque section explicitly.');
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
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $pipeline->addStage($this->stage(20, 'qmx.yaml', [], [self::file(['fail_onn' => null])]));

        try {
            $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));
            self::fail('An unknown root must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Unknown key "fail_onn"', $refusal->summary());
            self::assertStringContainsString('did you mean "fail_on"?', $refusal->summary());
            self::assertSame(['fail_onn'], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itJudgesEachAuthoredLayerWithoutAParallelRefusalTransport(): void
    {
        $accepted = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $accepted->addStage($this->stage(20, 'qmx.yaml', [], [self::file(['fail_on' => 'error'])]));
        $document = $accepted->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));
        self::assertSame('error', $document->resolved()->get('fail_on')?->plain());
        $refused = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $refused->addStage($this->stage(20, 'qmx.yaml', [], [self::file(['Fail_On' => 'error'])]));
        try {
            $refused->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));
            self::fail('The written document must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Key "Fail_On" in configuration file "/project/qmx.yaml" is not written in an accepted spelling; write "fail_on" (its snake_case, camelCase and kebab-case spellings are accepted).', $refusal->summary());
            self::assertSame('/project/qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['Fail_On'], $refusal->position()?->segments);
            self::assertSame('Fail_On', $refusal->position()->written);
        }
    }

    #[Test]
    public function itResolvesAnEmptyDocumentWhenNoStageHandsOverAWrittenLayer(): void
    {
        $pipeline = new ConfigurationPipeline(LayeredDocument::standaloneSections());
        $pipeline->addStage($this->stage(20, 'qmx.yaml', ['fail_on' => 'warning']));

        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        self::assertSame([], $document->resolved()->roots());
        self::assertSame([], $document->diagnostics());
    }

    /**
     * @param array<string, mixed>|null $values
     * @param list<AuthoredLayer> $authored
     */
    private function stage(int $priority, string $name, ?array $values, array $authored = []): ConfigurationStageInterface
    {
        return new class ($priority, $name, $values, $authored) implements ConfigurationStageInterface {
            /**
             * @param array<string, mixed>|null $values
             * @param list<AuthoredLayer> $authored
             */
            public function __construct(private readonly int $stagePriority, private readonly string $stageName, private readonly ?array $values, private readonly array $authored) {}
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
                return $this->values === null ? null : new ConfigurationLayer($this->stageName, $this->values, $this->authored);
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
            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration('coupling', NodeSchema::map(['framework_namespaces' => NodeSchema::list(NodeSchema::scalar(ScalarForm::String))]));
            }
        };
    }
}
