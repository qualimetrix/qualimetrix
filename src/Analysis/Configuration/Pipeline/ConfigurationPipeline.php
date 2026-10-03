<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;

/** Composes authored layers in stage order against explicit owner declarations. */
final class ConfigurationPipeline implements ConfigurationPipelineInterface
{
    /** @var list<ConfigurationStageInterface> */
    private array $stages = [];

    /** @var list<DocumentSectionSchemaInterface> */
    private array $sections = [];

    /** @param iterable<DocumentSectionSchemaInterface> $sections the sections owners declare */
    public function __construct(iterable $sections = [])
    {
        foreach ($sections as $section) {
            $this->addSection($section);
        }
    }

    public function resolve(ConfigurationResolutionRequest $request): ConfigurationDocument
    {
        $documents = [];
        $authored = [];
        $diagnostics = [];
        foreach ($this->stages() as $stage) {
            $layer = $stage->apply($request);
            if ($layer === null) {
                continue;
            }

            $authored = [...$authored, ...$layer->authored];
            $diagnostics = [...$diagnostics, ...$layer->diagnostics];

            foreach ($layer->authored === [] ? [null] : $layer->authored as $_) {
                $documents[] = ['source' => $layer->source, 'values' => $layer->values];
            }
        }

        $resolved = DocumentComposer::compose(new DocumentSchema([...ConfigurationRoot::cases(), ...$this->sections]), $authored);

        return new ConfigurationDocument($documents, $request->workingDirectory, $resolved, $diagnostics);
    }

    public function addSection(DocumentSectionSchemaInterface $section): void
    {
        $this->sections[] = $section;
    }

    public function addStage(ConfigurationStageInterface $stage): void
    {
        $this->stages[] = $stage;
    }

    /**
     * @return list<ConfigurationStageInterface>
     */
    public function stages(): array
    {
        $stages = [];
        foreach ($this->stages as $stage) {
            foreach ($stages as $index => $candidate) {
                if ($stage->priority() < $candidate->priority()) {
                    array_splice($stages, $index, 0, [$stage]);
                    continue 2;
                }
            }
            $stages[] = $stage;
        }

        return $stages;
    }
}
