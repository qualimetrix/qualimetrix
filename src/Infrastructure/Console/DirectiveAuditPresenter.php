<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdictRefusal;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Reporting\Formatter\CoverageNarrator;
use Qualimetrix\Reporting\ReportCoverage;

/**
 * The two projections of one directive audit.
 *
 * Both projections read the same report and selection. Human wording belongs
 * to the private text presenter; the shared selection and coverage note are
 * computed here, alongside the machine projection.
 *
 * **The text projection prints the claim; the machine projection prints the
 * key.** They are not the same thing on purpose. `Overrun` is the case that
 * forces it: the rule layer has no notion of which direction of a boundary is
 * stricter — `coupling.instability` is worse when higher, `cohesion.tcc` when
 * lower — so a directive that *tightens* a boundary and one that raises a
 * boundary the value had already passed produce the same observable. The word
 * "overrun" names the common half of that and would misinform the author of the
 * other half, so a human reads the sentence the verdict actually supports and a
 * script reads the enum value, whose stability is what it needs.
 */
final readonly class DirectiveAuditPresenter
{
    /** @var list<string> */
    private array $only;
    /** @var list<string> */
    private array $disabled;
    private ?string $note;

    /**
     * Built per report rather than injected as a service. The verdicts and
     * final selection belong to the same invocation.
     */
    public function __construct(
        private DirectiveAuditReport $report,
        private RuleEnablement $selection,
    ) {
        $filter = $selection->filter();
        $this->only = $filter === null ? [] : $filter->selectors;
        $this->disabled = $this->disabledStatements();
        $this->note = $this->coverageNote();
    }

    public function text(): string
    {
        return (new DirectiveAuditTextPresenter(
            $this->report,
            $this->only,
            $this->disabled,
            $this->note,
        ))->text();
    }

    public function json(int $exitCode): string
    {
        $report = $this->report;

        $scope = [
            'analyzed_files' => $report->coverage->analyzedFilesCount(),
            'generated_excluded_files' => $report->coverage->generatedExcludedFilesCount(),
            'failed_files' => $report->coverage->failedFilesCount(),
            'complete' => $report->coverage->isComplete(),
            'produced_findings' => $report->producedFindings,
        ];
        if ($this->note !== null) {
            $scope['note'] = $this->note;
        }

        return self::encode([
            'meta' => ProductIdentity::meta(gmdate('c')),
            'scope' => $scope,
            'selection' => ['only' => $this->only, 'disabled' => $this->disabled],
            'sweep' => $report->sweep->value,
            'directives' => array_map(self::verdictToArray(...), $report->verdicts),
            'summary' => DirectiveVerdictTally::of($report->verdicts)->summary(),
            'exit_code' => $exitCode,
        ]);
    }

    private function coverageNote(): ?string
    {
        $coverage = $this->report->coverage;
        if (!$coverage->isIntentionallyEmpty()) {
            return null;
        }

        return CoverageNarrator::describe(new ReportCoverage(
            $coverage->discoveredFiles(),
            $coverage->analyzedFilesCount(),
            $coverage->generatedExcludedFilesCount(),
            $coverage->failedFilesCount(),
            excluded: $coverage->excludedCount(),
        ));
    }

    /** @return list<string> */
    private function disabledStatements(): array
    {
        $statements = [];
        foreach ($this->selection->decisions() as $decision) {
            if ($decision->on) {
                continue;
            }
            if ($decision->decisiveStatements === [] && $decision->statement !== null) {
                $statements[$decision->statement] = true;
            }
            foreach ($decision->decisiveStatements as $statement) {
                $statements[$statement['text']] = true;
            }
        }

        return array_keys($statements);
    }

    /**
     * @return array{
     *     file: string, line: int, form: string, target: string, effect: string,
     *     reason: ?string, masked_by: ?array{file: string, line: int}, boundary_observable: bool,
     *     refusals: list<array{channel: string, message: string}>
     * }
     */
    private static function verdictToArray(DirectiveVerdict $verdict): array
    {
        $maskedBy = $verdict->maskedBy;

        return [
            'file' => $verdict->site->file->value(),
            'line' => $verdict->site->line,
            'form' => $verdict->site->form,
            'target' => $verdict->site->target,
            'effect' => $verdict->effect->value,
            'reason' => $verdict->reason?->value,
            'masked_by' => $maskedBy === null
                ? null
                : ['file' => $maskedBy->file->value(), 'line' => $maskedBy->line],
            'boundary_observable' => $verdict->boundaryObservable,
            'refusals' => array_map(static fn(DirectiveVerdictRefusal $refusal): array => [
                'channel' => $refusal->channel->code,
                'message' => $refusal->message,
            ], $verdict->refusals),
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload): string
    {
        return json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }
}
