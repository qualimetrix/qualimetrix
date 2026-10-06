<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/** Where in one layer the reading stands: source, authored path, canonical path. */
final readonly class ReadingContext
{
    /**
     * @param list<string> $authoredPath
     * @param list<string> $canonicalPath
     */
    private function __construct(
        public ConfigurationOrigin $origin,
        public bool $positioned,
        public array $authoredPath,
        public array $canonicalPath,
        public bool $insideList,
        public int $layerIndex,
    ) {}

    public static function of(AuthoredLayer $layer, int $layerIndex): self
    {
        return new self($layer->origin, $layer->positioned, [], [], false, $layerIndex);
    }

    public function child(string $authored, string $canonical, AuthoredNode $node): self
    {
        return $this->descend($authored, $canonical, $node->locator, $node->authoredExpression);
    }

    /** An item of this list: everything below it is inside a list. */
    public function item(int $index, AuthoredNode $node): self
    {
        $at = $this->child((string) $index, (string) $index, $node);

        return new self($at->origin, $at->positioned, $at->authoredPath, $at->canonicalPath, true, $at->layerIndex);
    }

    /** A key of this map as a spot of its own, whatever value is written there. */
    public function key(string $authored, string $canonical): self
    {
        return $this->descend($authored, $canonical, null, null);
    }

    /** @param list<string> $path */
    public function atCanonicalPath(array $path): self
    {
        return new self(
            $this->origin,
            $this->positioned,
            $this->authoredPath,
            $path,
            $this->insideList,
            $this->layerIndex,
        );
    }

    private function descend(string $authored, string $canonical, ?string $locator, ?string $authoredExpression): self
    {
        return new self(
            $locator === null ? $this->origin : ($authoredExpression === null
                ? $this->origin->locatedAt($locator)
                : $this->origin->locatedAtAuthoredWrite($locator, $authoredExpression)),
            $this->positioned,
            [...$this->authoredPath, $authored],
            [...$this->canonicalPath, $canonical],
            $this->insideList,
            $this->layerIndex,
        );
    }

    public function provenance(AuthoredNode $node): Provenance
    {
        return new Provenance($this->origin, $this->positioned ? $this->authoredPath : null, $this->layerIndex, $node->line);
    }

    /** The spot for a sentence: `"cache.enabled" in configuration file "qmx.yaml"`. */
    public function where(): string
    {
        return $this->positioned && $this->authoredPath !== []
            ? \sprintf('"%s" in %s', Provenance::display($this->authoredPath), $this->origin->describe())
            : $this->origin->describe();
    }

    /**
     * A refusal of what this layer wrote at this spot.
     *
     * @param ?list<string> $accepted non-null for a closed position
     */
    public function refusal(string $summary, ?string $written = null, ?array $accepted = null): ConfigurationRefusal
    {
        if (!$this->positioned) {
            return ConfigurationRefusal::aboutInput($this->origin, $summary);
        }

        $written ??= $this->authoredPath[\count($this->authoredPath) - 1] ?? '';
        $position = $accepted === null
            ? RefusedPosition::open($this->authoredPath, $written)
            : RefusedPosition::closed($this->authoredPath, $written, $accepted);

        return ConfigurationRefusal::at($this->origin, $position, $summary);
    }
}
