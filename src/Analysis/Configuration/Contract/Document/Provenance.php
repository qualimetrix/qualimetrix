<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/**
 * Where one written value came from: the source instance, the path of keys as
 * the author spelled them, and the line when the format reports one.
 *
 * `path` is null for a source without document positions — a command-line
 * option is named by the origin's locator instead.
 * `layerIndex` is the layer's precedence within one composed document. Values
 * from different documents cannot be combined in a refusal.
 */
final readonly class Provenance
{
    /** @param ?list<string> $path */
    public function __construct(
        public ConfigurationOrigin $origin,
        public ?array $path,
        public int $layerIndex,
        public ?int $line = null,
    ) {}

    /** The refused spot for a value this provenance wrote; null without document positions. */
    public function position(): ?RefusedPosition
    {
        if ($this->path === null || $this->path === []) {
            return null;
        }

        return RefusedPosition::open($this->path, $this->path[\count($this->path) - 1]);
    }

    /** The authored path for a sentence: `architecture.layers[0].name`. */
    public function displayPath(): string
    {
        return self::display($this->path ?? []);
    }

    /** @param list<string> $path */
    public static function display(array $path): string
    {
        $text = '';
        foreach ($path as $segment) {
            $text .= ctype_digit($segment) ? \sprintf('[%s]', $segment) : ($text === '' ? $segment : '.' . $segment);
        }

        return $text;
    }

    /**
     * A refusal naming every writer; the position is where the last of them
     * wrote, the spot an author edits first.
     *
     * An omitted position uses the last writer's position; an explicit null
     * keeps a positionless refusal. Writers must belong to one document.
     *
     * @param non-empty-list<self> $writers in any order; ties retain the supplied order
     */
    public static function refusalOf(array $writers, string $summary, ?RefusedPosition $position = null): ConfigurationRefusal
    {
        usort($writers, static fn(self $left, self $right): int => $left->layerIndex <=> $right->layerIndex);
        $last = $writers[\count($writers) - 1];
        if (\func_num_args() < 3) {
            $position = $last->position();
        }

        $origins = [];
        foreach ($writers as $writer) {
            $origins[serialize($writer->origin)] = $writer->origin;
        }

        if (\count($origins) > 1) {
            return ConfigurationRefusal::acrossLayers(array_values($origins), $position, $summary);
        }

        return $position === null
            ? ConfigurationRefusal::aboutInput($last->origin, $summary)
            : ConfigurationRefusal::at($last->origin, $position, $summary);
    }
}
