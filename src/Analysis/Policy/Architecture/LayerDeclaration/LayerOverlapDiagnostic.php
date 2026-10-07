<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\Observation\DiagnosticSampleList;
use Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidence;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Partial losses under non-pattern precedence, rather than an unreachable layer. */
final class LayerOverlapDiagnostic
{
    private const string OCCURRENCE_KIND = 'declared-layer-overlap';

    /** @return list<Finding> */
    public static function forPrecedence(LayerEvidence $evidence): array
    {
        $layers = $evidence->architecture->registry()->layerNames();
        sort($layers, \SORT_STRING);
        $findings = [];
        foreach ($layers as $later) {
            if (($evidence->assignedClassHits[$later] ?? 0) === 0) {
                continue;
            }
            $pairs = $evidence->lostByPrecedence($later);
            usort($pairs, static fn(array $a, array $b): int => strcmp($a['earlier'], $b['earlier']));
            foreach ($pairs as $pair) {
                $findings[] = self::finding($later, $pair['earlier'], $pair['classes']);
            }
        }
        return $findings;
    }

    /** @param list<string> $classes */
    private static function finding(string $later, string $earlier, array $classes): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME,
            code: ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME,
            message: \sprintf('Layer "%s" owns analysed classes, but %d class(es) matching its non-pattern criteria were taken by earlier layer "%s": %s. Assignment follows declaration order.', $later, \count($classes), $earlier, DiagnosticSampleList::format($classes)),
            severity: Severity::Info,
            recommendation: \sprintf('If "%s" should own these classes, declare it before "%s"; otherwise keep this precedence or narrow its criteria.', $later, $earlier),
            occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, ['earlier' => $earlier, 'later' => $later]),
        );
    }
}
