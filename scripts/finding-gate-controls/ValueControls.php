<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\DeclaredValues;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Tsv;

final class ValueControls
{
    public static function idleIntent(): Control
    {
        return Control::writing(
            'value-unused-intent',
            'a declared field whose value no invocation changes',
            Mutation::replace([
                'finding-gate/' . DeclaredValues::INDEX => Tsv::render(DeclaredValues::COLUMNS, [['field', 'nothingPublished', '*', 'A value transition that the run never observes.']]),
            ], 'an unused value intention'),
            '--derive-declarations',
            [new Expectation(FailureClass::VALUE_STALE, DeclaredValues::INDEX)],
            ['finding-gate/' . DeclaredValues::INDEX],
        );
    }
}
