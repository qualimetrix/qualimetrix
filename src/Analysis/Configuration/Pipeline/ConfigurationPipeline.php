<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;

/**
 * Configuration resolution pipeline.
 *
 * Collects configuration from multiple stages (defaults, composer, config file, cli)
 * and merges them according to priority order.
 *
 * Capability-specific configuration remains an ordered normalized document
 * until its owning capability explicitly consumes it. Alongside it, the
 * layers a stage hands over as written are composed by the document engine
 * against the declared sections; a root no section declares yet passes
 * through unread.
 */
final class ConfigurationPipeline implements ConfigurationPipelineInterface
{
    /** @var list<ConfigurationStageInterface> */
    private array $stages = [];

    /** @var list<DocumentSectionSchemaInterface> */
    private array $sections = [];

    public function __construct() {}

    public function resolve(ConfigurationResolutionRequest $request): ConfigurationDocument
    {
        $documents = [];
        $authored = [];
        foreach ($this->stages() as $stage) {
            $layer = $stage->apply($request);
            if ($layer === null) {
                continue;
            }

            $authored = [...$authored, ...$layer->authored];

            if ($layer->documents === []) {
                $documents[] = ['source' => $layer->source, 'values' => $layer->values];
                continue;
            }
            foreach ($layer->documents as $values) {
                $documents[] = ['source' => $layer->source, 'values' => $values];
            }
        }

        return new ConfigurationDocument(
            $documents,
            $request->workingDirectory,
            DocumentComposer::compose(new DocumentSchema($this->sections, admitsUndeclaredRoots: true), $authored),
        );
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
