<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;

final class RankingControls
{
    public static function tieBreak(): Control
    {
        return Control::red(
            'ranking-file-tie-break',
            'equal-score findings reverse the product file tie-break',
            Mutation::edit('src/Analysis/Evidence/Prioritization/Impact/ImpactCalculator.php', [
                '$cmp = $a->finding->location->pathString() <=> $b->finding->location->pathString();' => '$cmp = $b->finding->location->pathString() <=> $a->finding->location->pathString();',
            ], 'reverse the product ranking file tie-break'),
            [new Expectation(FailureClass::RANKING_ORDER_MISMATCH, '|format:json|record:'),
                new Expectation(FailureClass::RANKING_ORDER_MISMATCH, 'case:baseline-cycle|check:baseline-source|record:')],
        );
    }

    public static function inputOrder(): Control
    {
        return Control::red(
            'ranking-input-order',
            'otherwise tied findings reverse the product input order',
            Mutation::edit('src/Analysis/Evidence/Prioritization/Impact/ImpactCalculator.php', [
                'foreach ($findings as $finding) {' => 'foreach (array_reverse($findings) as $finding) {',
            ], 'reverse the product finding input order'),
            [new Expectation(FailureClass::RANKING_ORDER_MISMATCH, '|format:json|record:'),
                new Expectation(FailureClass::RANKING_ORDER_MISMATCH, 'case:scoped-layers|check:baseline-source|record:')],
        );
    }

}
