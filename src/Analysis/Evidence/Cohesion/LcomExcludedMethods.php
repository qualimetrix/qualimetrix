<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;

use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Reports authored method exclusions absent from a whole-project method universe. */
final class LcomExcludedMethods
{
    /**
     * @return list<Finding>
     */
    public static function findings(AnalysisContext $context, LcomOptions $options): array
    {
        if ($options->excludeMethods === null || $options->excludeMethods === []) {
            return [];
        }

        $scopeJudged = $context->projectScope->judgesNamespaceClaims();
        $methods = $scopeJudged ? self::declaredMethods($context) : [];
        $declaration = LcomRule::channelDeclarations()['cohesion.unmatched-exclude-method'];
        $findings = [];
        $seen = [];
        foreach ($options->excludeMethods as $authored) {
            $normalized = strtolower($authored);
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            if (!$context->admit(
                LcomRule::NAME,
                new FindingChannel('cohesion.unmatched-exclude-method'),
                SymbolLevel::Project,
                PopulationIdentity::selector($normalized, 'configured-method-selector'),
                $declaration,
                [GateInput::context('namespaceClaimsJudged', $scopeJudged)],
            )) {
                continue;
            }
            if (isset($methods[$normalized])) {
                continue;
            }
            $findings[] = self::unmatchedFinding($authored, $normalized);
        }

        return $findings;
    }

    /** @return list<PopulationGate> */
    public static function populationGates(): array
    {
        return [new PopulationGate(
            'method-universe',
            new FindingChannel('cohesion.unmatched-exclude-method'),
            SymbolLevel::Project,
            'configured-method-selector',
            new ContextGuard('namespaceClaimsJudged'),
            'The project method universe is not judged.',
        )];
    }

    /** @return array<string, true> */
    private static function declaredMethods(AnalysisContext $context): array
    {
        $methods = [];
        foreach ($context->metrics->allCallables() as $callable) {
            if ($callable->callableKind === CallableKind::Method && $callable->symbolPath->member !== null) {
                $methods[strtolower($callable->symbolPath->member)] = true;
            }
        }

        return $methods;
    }

    private static function unmatchedFinding(string $authored, string $normalized): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: 'cohesion.unmatched-exclude-method',
            code: 'cohesion.unmatched-exclude-method',
            message: \sprintf('The exclude_methods name "%s" matched no method declared in this project.', $authored),
            severity: Severity::Warning,
            metricValue: 1,
            occurrenceKey: OccurrenceKey::semantic('unmatched-exclude-method', ['method' => $normalized]),
        );
    }
}
