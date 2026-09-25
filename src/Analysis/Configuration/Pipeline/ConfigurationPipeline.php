<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\DocumentRoots;

/**
 * Configuration resolution pipeline.
 *
 * Collects configuration from multiple stages (defaults, composer, config file, cli)
 * and merges them according to priority order.
 *
 * Capability-specific configuration remains an ordered normalized document
 * until its owning capability explicitly consumes it. Alongside it, the
 * layers a stage hands over as written are composed by the document engine
 * against the roots Configuration declares and the sections owners register;
 * a known root nobody declares yet is carried unread
 * ({@see DocumentRoots::completing()}).
 */
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
        $deferred = [];
        foreach ($this->stages() as $stage) {
            $layer = $stage->apply($request);
            if ($layer === null) {
                continue;
            }

            $authored = [...$authored, ...$layer->authored];
            $deferred = [...$deferred, ...$layer->deferredRefusals];

            if ($layer->documents === []) {
                $documents[] = ['source' => $layer->source, 'values' => $layer->values];
                continue;
            }
            foreach ($layer->documents as $values) {
                $documents[] = ['source' => $layer->source, 'values' => $values];
            }
        }

        $resolved = DocumentComposer::compose(new DocumentSchema(DocumentRoots::completing($this->sections)), $authored);

        if ($deferred !== []) {
            throw $deferred[0];
        }

        return new ConfigurationDocument($documents, $request->workingDirectory, $resolved);
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
