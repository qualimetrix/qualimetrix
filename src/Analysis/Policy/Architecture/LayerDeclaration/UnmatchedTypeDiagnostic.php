<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidence;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Builds one warning for each authored layer type this run proved absent. */
final class UnmatchedTypeDiagnostic
{
    private const string OCCURRENCE_KIND = 'unmatched-layer-type';

    /** @return list<Finding> */
    public static function forEvidence(LayerEvidence $evidence, ProjectScopeJudgement $scope): array
    {
        $configuration = $evidence->architecture;
        $judgement = $configuration->registry()->contextFactory()->knownTypes()->unmatched($configuration->namedTypes(), $scope);
        if (!$judgement->isJudged()) {
            return [];
        }

        $findings = [];
        foreach ($judgement->occurrences as $occurrence) {
            $type = $occurrence->namedType;
            $provenance = $type->provenance;
            $position = $provenance->displayPath();
            $where = $provenance->origin->describe()
                . ($position === '' ? '' : ' at ' . $position)
                . ($provenance->line === null ? '' : ':' . $provenance->line);
            $suggestion = $occurrence->suggestedSpelling === null
                ? ''
                : \sprintf(' did you mean %s?', $occurrence->suggestedSpelling);

            $findings[] = new Finding(
                location: Location::none(),
                subject: MetricSubject::aggregate(SymbolPath::forProject()),
                symbolPath: SymbolPath::forProject(),
                ruleName: ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME,
                code: ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME,
                message: \sprintf(
                    'Layer criterion type "%s" declared by %s was not found in the analysed declarations, dependency graph, PHP built-ins, or the analysed project\'s Composer install.%s',
                    $type->fqn,
                    $where,
                    $suggestion,
                ),
                severity: Severity::Warning,
                recommendation: 'Correct the type spelling, install the dependency that declares it, or remove the stale criterion.',
                occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, $occurrence->identityEvidence()),
            );
        }

        return $findings;
    }
}
