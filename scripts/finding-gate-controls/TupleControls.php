<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\CaseOutcome;
use QmxFindingGate\Corpus;
use QmxFindingGate\EquivalenceTuple;
use QmxFindingGate\FailureClass;
use RuntimeException;

/** Unannounced published members cannot disappear behind the tracked tuple. */
final class TupleControls
{
    public static function publisherDrift(): Control
    {
        return Control::red(
            'tuple-publisher-drift',
            'the JSON publisher adds a member absent from the tracked equivalence tuple',
            self::addedMember(),
            [new Expectation(FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH), ...self::recordExpectations('candidate')],
        );
    }

    public static function addedMemberWithoutIntent(): Control
    {
        return Control::red(
            'tuple-added-member-without-intent',
            'the candidate tuple includes a new member but no field intent states the reference shape',
            self::addedMember()->and(Mutation::append(
                EquivalenceTuple::TRACKED_PATH,
                'probe' . "\t" . EquivalenceTuple::source() . "\n",
                'the tracked candidate tuple includes the unannounced member',
            )),
            self::recordExpectations('reference'),
        );
    }

    private static function addedMember(): Mutation
    {
        return Mutation::edit(
            'src/Reporting/Formatter/Json/JsonFindingSection.php',
            ["            'acceptedLevel' => \$this->formatAcceptedLevel(\$finding),\n        ];" => "            'acceptedLevel' => \$this->formatAcceptedLevel(\$finding),\n            'probe' => 1,\n        ];"],
            'the published finding receives an extra observed member',
        );
    }

    /** @return list<Expectation> */
    private static function recordExpectations(string $side): array
    {
        $required = [];
        foreach (Corpus::load(\dirname(__DIR__, 2))->cases as $case) {
            if ($case->channels === [] || !CaseOutcome::applies(CaseOutcome::CHECK_TUPLE, CaseOutcome::of($case, $side))) {
                continue;
            }
            $required[] = new Expectation(FailureClass::FINDING_TUPLE_MISMATCH, $side . ' / ' . $case->id . ' / finding');
            $required[] = new Expectation(FailureClass::RECORD_PROJECTION_MISMATCH, $side . ' / case:' . $case->id . '|');
        }
        if ($required === []) {
            throw new RuntimeException('The tuple member control has no populated publication to judge.');
        }
        return $required;
    }
}
