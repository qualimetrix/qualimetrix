<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Everything a step declares besides its rename maps and its normalization,
 * loaded together because one form's rows are read through another's: a
 * withdrawn record carries the reference's fields, which the declared field
 * changes decide.
 */
final class Declarations
{
    private function __construct(
        public readonly DeclaredDelta $delta,
        public readonly DeclaredFieldMoves $fieldMoves,
        public readonly DeclaredRecords $records,
        public readonly DeclaredValues $values,
        public readonly DeclaredFields $fields,
        public readonly DeclaredOutcomes $outcomes,
        public readonly DeclaredSurfaces $surfaces,
        public readonly DeclaredStructuralMaps $structuralMaps,
    ) {}

    public static function load(string $candidateRoot): self
    {
        $root = $candidateRoot . '/finding-gate';
        $fields = DeclaredFields::load($root);

        // The tuple is read only when a record needs it, so a tree without
        // declared records loads exactly what it loaded before this form existed.
        $candidateFields = is_file($root . '/' . DeclaredRecords::INDEX) ? EquivalenceTuple::load($candidateRoot)->fields : [];

        return new self(
            DeclaredDelta::load($root),
            DeclaredFieldMoves::load($root),
            DeclaredRecords::load($root, $candidateFields, $fields->referenceFields($candidateFields)),
            DeclaredValues::load($root),
            $fields,
            DeclaredOutcomes::load($root),
            DeclaredSurfaces::load($root),
            DeclaredStructuralMaps::load($root),
        );
    }

    /**
     * The count of every form the report publishes besides the declared deltas
     * and field moves, by its report key ({@see GateReport::DECLARATION_COUNTS}).
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'declaredRecordCount' => $this->records->count(),
            'declaredValueCount' => $this->values->count(),
            'declaredFieldCount' => $this->fields->count(),
            'declaredOutcomeCount' => $this->outcomes->count(),
            'declaredSurfaceCount' => $this->surfaces->count(),
            'structuralMapCount' => $this->structuralMaps->count(),
        ];
    }
}
