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
        $files = [
            'finding-gate/' . DeclaredRecords::INDEX => Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', '*', 'json', 'format:json', '{"channel":"nothing.published"}', 'A withdrawal that no source publication measures.']]),
        ];
        $mutation = is_file(\dirname(__DIR__, 2) . '/finding-gate/' . DeclaredRecords::INDEX)
            ? Mutation::replace($files, 'an unused record selector')
            : Mutation::create($files, 'an unused record selector');
        return Control::writing(
            'record-unused-selector',
            'an explained selector under which no authoritative publication loses a record',
            $mutation,
            '--derive-declarations',
            [new Expectation(FailureClass::RECORD_STALE, DeclaredRecords::INDEX)],
            ['finding-gate/' . DeclaredRecords::INDEX],
        );
    }
}
