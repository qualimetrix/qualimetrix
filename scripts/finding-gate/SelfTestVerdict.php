<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The verdict a report reaches and the exit code it maps to.
 */
final class SelfTestVerdict extends SelfTestGroup
{
    public function verdicts(): void
    {
        $green = new GateReport();
        $this->same(GateReport::VERDICT_GREEN, $green->verdict(), 'an unrestricted run with no failure is green');
        $this->same(GateReport::EXIT_GREEN, $green->exitCode(), 'green exits 0');

        $partial = new GateReport();
        $partial->limit('the corpus was restricted');
        $this->same(GateReport::VERDICT_PARTIAL, $partial->verdict(), 'a restricted run is partial, not green');
        $this->same(GateReport::EXIT_PARTIAL, $partial->exitCode(), 'partial has its own exit code');
        $rendered = $partial->render();
        $this->assert(str_contains($rendered, 'PARTIAL'), 'a partial run says so');
        $this->assert(
            !str_contains($rendered, 'finding-equivalent under the declared maps'),
            'a partial run does not print the full-equivalence verdict',
        );

        $red = new GateReport();
        $red->limit('the corpus was restricted');
        $red->fail(FailureClass::RUN_FAILED, 'scope', 'detail');
        $this->same(GateReport::VERDICT_RED, $red->verdict(), 'a failure outranks a restriction');
        $this->same(GateReport::EXIT_RED, $red->exitCode(), 'red exits 1');
    }
}
