<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredShape;
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
            if ($layer->authored === [] && $layer->values === [] && $layer->file === null) {
                // Only discovery notices: nothing was read, so nothing was applied.
                continue;
            }

            foreach ($layer->authored === [] ? [null] : $layer->authored as $file) {
                $documents[] = self::source($layer, $file);
            }
        }

        self::assertNoPerFileRoots($authored);
        $resolved = DocumentComposer::compose(new DocumentSchema([...ConfigurationRoot::cases(), ...$this->sections]), $authored);

        return new ConfigurationDocument($documents, $request->workingDirectory, $resolved, $diagnostics);
    }

    /**
     * One applied source: an imported file is a source of its own, while the
     * stage's name is its root's.
     *
     * @return array{source: string, values: array<string, mixed>, file: ?\Qualimetrix\Core\FileTarget\FileIdentity, name: string}
     */
    private static function source(ConfigurationLayer $layer, ?AuthoredLayer $file): array
    {
        if ($file === null) {
            return ['source' => $layer->source, 'values' => $layer->values, 'file' => $layer->file, 'name' => $layer->source];
        }

        return [
            'source' => $file->origin->importer() === null ? $layer->source : (string) $file->origin->locator(),
            'values' => $layer->values,
            'file' => $file->file,
            'name' => $file->origin->describe(),
        ];
    }

    /**
     * A per-file root reaching the composer would merge as a plain list and
     * vanish without effect; every stage that reads one must take it out.
     *
     * @param list<AuthoredLayer> $authored
     */
    private static function assertNoPerFileRoots(array $authored): void
    {
        foreach ($authored as $layer) {
            foreach (ConfigurationRoot::perFile() as $root) {
                if ($layer->root->shape === AuthoredShape::Mapping && \array_key_exists($root->value, $layer->root->children)) {
                    throw new LogicException(\sprintf('The layer of %s still carries "%s" at composition; its stage must read and remove it.', $layer->origin->describe(), $root->value));
                }
            }
        }
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
