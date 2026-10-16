<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

/** One authored named type that this run did not meet. */
final readonly class UnmatchedTypeOccurrence
{
    public function __construct(
        public NamedType $namedType,
        public ?string $suggestedSpelling,
    ) {}

    /** @return array<string, int|string> */
    public function identityEvidence(): array
    {
        $provenance = $this->namedType->provenance;
        $evidence = [
            'fqn' => $this->namedType->fqn,
            'path' => json_encode($provenance->path ?? [], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            'layer_index' => $provenance->layerIndex,
            'line' => $provenance->line ?? -1,
        ];

        $origin = $provenance->origin;
        for ($depth = 0; $origin !== null; ++$depth, $origin = $origin->importer()) {
            $evidence['origin_' . $depth . '_source'] = $origin->source()->value;
            $evidence['origin_' . $depth . '_locator'] = $origin->locator() ?? '';
        }

        return $evidence;
    }

    public static function identityOf(NamedType $type): string
    {
        $occurrence = new self($type, null);

        return json_encode($occurrence->identityEvidence(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
