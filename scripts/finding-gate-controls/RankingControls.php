<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;

final class RankingControls
{
    public static function inputOrder(): Control
    {
        return Control::red(
            'ranking-input-order',
            'otherwise tied findings reverse the product input order',
            Mutation::edit('src/Analysis/Evidence/Prioritization/Impact/ImpactCalculator.php', [
                'foreach ($findings as $finding) {' => 'foreach (array_reverse($findings) as $finding) {',
            ], 'reverse the product finding input order'),
            [new Expectation(FailureClass::RANKING_ORDER_MISMATCH, '|format:json|record:')],
        );
    }

}
