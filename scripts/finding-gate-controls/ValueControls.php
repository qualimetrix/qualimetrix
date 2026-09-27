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
        $files = [
            'finding-gate/' . DeclaredValues::INDEX => Tsv::render(DeclaredValues::COLUMNS, [['field', 'nothingPublished', '*', 'A value transition that the run never observes.']]),
        ];
        $mutation = is_file(\dirname(__DIR__, 2) . '/finding-gate/' . DeclaredValues::INDEX)
            ? Mutation::replace($files, 'an unused value intention')
            : Mutation::create($files, 'an unused value intention');
        return Control::writing(
            'value-unused-intent',
            'a declared field whose value no invocation changes',
            $mutation,
            '--derive-declarations',
            [new Expectation(FailureClass::VALUE_STALE, DeclaredValues::INDEX)],
            ['finding-gate/' . DeclaredValues::INDEX],
        );
    }
}
