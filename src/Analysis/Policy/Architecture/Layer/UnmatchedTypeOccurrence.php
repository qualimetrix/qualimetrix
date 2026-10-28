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

    /**
     * The type and the authored path alone. `architecture.layers` is replaced
     * whole by its last writer, so one run's criteria all share one file and
     * the path names a single value of it: the file, its importers, its layer
     * index and line would only re-identify the finding when the criterion
     * moves into an imported file or a preset is added below it.
     *
     * @return array<string, string>
     */
    public function identityEvidence(): array
    {
        return [
            'fqn' => $this->namedType->fqn,
            'path' => json_encode($this->namedType->provenance->path ?? [], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        ];
    }

    public static function identityOf(NamedType $type): string
    {
        $occurrence = new self($type, null);

        return json_encode($occurrence->identityEvidence(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
