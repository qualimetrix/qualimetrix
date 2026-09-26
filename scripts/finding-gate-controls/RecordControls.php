<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\DeclaredRecords;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Tsv;

final class RecordControls
{
    public static function idleSelector(): Control
    {
        return Control::writing(
            'record-unused-selector',
            'an explained selector under which no authoritative publication loses a record',
            Mutation::replace([
                'finding-gate/' . DeclaredRecords::INDEX => Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', '*', 'json', 'format:json', '{"channel":"nothing.published"}', 'A withdrawal that no source publication measures.']]),
            ], 'an unused record selector'),
            '--derive-declarations',
            [new Expectation(FailureClass::RECORD_STALE, DeclaredRecords::INDEX)],
            ['finding-gate/' . DeclaredRecords::INDEX],
        );
    }
}
