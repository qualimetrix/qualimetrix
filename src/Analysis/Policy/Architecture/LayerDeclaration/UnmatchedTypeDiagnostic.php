<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\Layer\UnmatchedTypeOccurrence;
use Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidence;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Builds one warning for each authored layer type this run proved absent. */
final class UnmatchedTypeDiagnostic
{
    private const string OCCURRENCE_KIND = 'unmatched-layer-type';

    /** @return list<Finding> */
    public static function forEvidence(LayerEvidence $evidence, ProjectScopeJudgement $scope, AnalysisContext $context): array
    {
        $configuration = $evidence->architecture;
        $types = $configuration->namedTypes();
        $judgement = $configuration->registry()->contextFactory()->knownTypes()->unmatched($types, $scope);
        $declaration = LayerDeclarationRule::channelDeclarations()[ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME];
        foreach ($types as $type) {
            $context->admit(LayerDeclarationRule::NAME, new FindingChannel(ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME), SymbolLevel::Project, PopulationIdentity::selector(UnmatchedTypeOccurrence::identityOf($type), 'authored-type-occurrence'), $declaration, [GateInput::context('namedTypesJudged', $judgement->isJudged())]);
        }
        if (!$judgement->isJudged()) {
            return [];
        }

        $findings = [];
        foreach ($judgement->occurrences as $occurrence) {
            $findings[] = self::finding($occurrence);
        }

        return $findings;
    }

    /** @return list<PopulationGate> */
    public static function populationGates(): array
    {
        return [new PopulationGate('named-type-scope', new FindingChannel(ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME), SymbolLevel::Project, 'authored-type-occurrence', new ContextGuard('namedTypesJudged'), 'Named type absence requires complete scope and a consulted Composer install.')];
    }

    private static function finding(UnmatchedTypeOccurrence $occurrence): Finding
    {
        $type = $occurrence->namedType;

        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME,
            code: ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME,
            message: \sprintf(
                'Layer criterion type "%s" declared by %s was not found in the analysed declarations, dependency graph, PHP built-ins, or the analysed project\'s Composer install.%s',
                $type->fqn,
                self::where($occurrence),
                $occurrence->suggestedSpelling === null ? '' : \sprintf(' did you mean %s?', $occurrence->suggestedSpelling),
            ),
            severity: Severity::Warning,
            recommendation: 'Correct the type spelling, install the dependency that declares it, or remove the stale criterion.',
            occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, $occurrence->identityEvidence()),
        );
    }

    private static function where(UnmatchedTypeOccurrence $occurrence): string
    {
        $provenance = $occurrence->namedType->provenance;
        $position = $provenance->displayPath();

        return $provenance->origin->describe()
            . ($position === '' ? '' : ' at ' . $position)
            . ($provenance->line === null ? '' : ':' . $provenance->line);
    }
}
