<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdictRefusal;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport;

final readonly class DirectiveAuditTextPresenter
{
    /**
     * @param list<string> $only
     * @param list<string> $disabled
     */
    public function __construct(
        private DirectiveAuditReport $report,
        private array $only,
        private array $disabled,
        private ?string $coverageNote,
    ) {}

    public function text(): string
    {
        return implode("\n", [
            'Directive audit',
            '',
            ...$this->scopeLines(),
            \sprintf('  Sweep        %s', self::sweepLine($this->report->sweep)),
            ...$this->selectionLines(),
            '',
            ...$this->auditLines(),
            '',
        ]) . "\n";
    }

    /** @return list<string> */
    private function scopeLines(): array
    {
        $report = $this->report;
        $lines = [\sprintf(
            '  Scope        %d file(s) analysed, %d finding(s) produced',
            $report->coverage->analyzedFilesCount(),
            $report->producedFindings,
        )];
        if ($report->coverage->generatedExcludedFilesCount() > 0) {
            // A narrowed scope can turn a live verdict dead, so state the
            // narrowing instead of leaving it to a smaller file count.
            $lines[] = \sprintf(
                '  Generated    %d file(s) skipped as generated, and no directive in them was judged',
                $report->coverage->generatedExcludedFilesCount(),
            );
        }
        if (!$report->coverage->isComplete()) {
            $lines[] = \sprintf(
                '  Incomplete   %d file(s) failed to parse — no directive can be called dead by this run',
                $report->coverage->failedFilesCount(),
            );
        }
        if ($this->coverageNote !== null) {
            $lines[] = '  Note         ' . $this->coverageNote;
        }

        return $lines;
    }

    /** @return list<string> */
    private function auditLines(): array
    {
        if ($this->report->verdicts === []) {
            return ['  No inline directives in the analysed scope.'];
        }

        $lines = [];
        foreach ($this->report->verdicts as $verdict) {
            foreach ($this->verdictLines($verdict) as $line) {
                $lines[] = $line;
            }
        }
        $lines[] = '';
        $lines[] = '  ' . DirectiveVerdictTally::of($this->report->verdicts)->line();

        return $lines;
    }

    /** @return list<string> */
    private function verdictLines(DirectiveVerdict $verdict): array
    {
        return [
            \sprintf(
                '  %s:%d  %s%s',
                $verdict->site->file->value(),
                $verdict->site->line,
                self::tag($verdict->site->form),
                $verdict->site->target === '' ? '' : ' ' . $verdict->site->target,
            ),
            ...array_map(static fn(string $sentence): string => '      ' . $sentence, $this->statement($verdict)),
        ];
    }

    /**
     * How the verdicts were measured, said in the report that carries them.
     *
     * Printed on every run and not only on the expensive one: a reader
     * comparing two reports has to be able to see which measurement each came
     * from, and a line that appears only sometimes is one a reader learns to
     * stop looking for.
     */
    private static function sweepLine(DirectiveSweepScope $sweep): string
    {
        return match ($sweep) {
            DirectiveSweepScope::Narrow => 'narrow — each directive is judged by re-executing the rule it addresses',
            DirectiveSweepScope::Full => 'full — each directive is judged by re-executing every enabled rule',
        };
    }

    /** @return list<string> */
    private function selectionLines(): array
    {
        $lines = [];
        if ($this->only !== []) {
            $lines[] = '  Only         ' . implode(', ', $this->only);
        }
        if ($this->disabled !== []) {
            $lines[] = '  Disabled     ' . implode(', ', $this->disabled);
        }

        return $lines;
    }

    /**
     * What the verdict actually supports, in sentences.
     *
     * @return list<string>
     */
    private function statement(DirectiveVerdict $verdict): array
    {
        return match ($verdict->effect) {
            DirectiveEffect::Effective => ['effective: removing it changes what the rules produce.'],
            DirectiveEffect::Overrun => [
                'applied; nothing moved except the boundary it prints.',
                'Whether that is a promise unkept or a boundary deliberately tightened is not',
                'observable here — the rule layer has no notion of stricter.',
            ],
            DirectiveEffect::Inert => $this->inertStatement($verdict),
            DirectiveEffect::Unmeasured => self::unmeasuredStatement($verdict),
            DirectiveEffect::Refused => array_map(
                static fn(DirectiveVerdictRefusal $refusal): string => 'refused: ' . $refusal->channel->code . ': ' . $refusal->message,
                $verdict->refusals,
            ),
        };
    }

    /** @return list<string> */
    private function inertStatement(DirectiveVerdict $verdict): array
    {
        // Under an incomplete run the claim is narrowed rather than repeated:
        // the header says the run failed to read part of the tree, and a line
        // that still reads "removing it changes nothing" would be the sentence
        // the header just withdrew.
        $sentences = $this->report->coverage->isComplete()
            ? ['inert: removing it changes nothing.']
            : ['inert in what this run managed to read; the rest of the tree was not measured.'];

        if (!$verdict->boundaryObservable) {
            $sentences[] = 'The addressed rule publishes no boundary with its finding, so a boundary the';
            $sentences[] = 'measured value had already passed would look exactly like this. Not asked.';
        }

        return $sentences;
    }

    /** @return list<string> */
    private static function unmeasuredStatement(DirectiveVerdict $verdict): array
    {
        $reason = match ($verdict->reason) {
            DirectiveUnmeasurableReason::ProducerDisabled
                => 'unmeasured: the producer of the addressed channel did not run.',
            DirectiveUnmeasurableReason::Masked => self::maskedSentence($verdict->maskedBy),
            null => 'unmeasured.',
        };

        return [$reason];
    }

    private static function maskedSentence(?DirectiveSite $maskedBy): string
    {
        if ($maskedBy === null) {
            return 'unmeasured: another directive of the same rule covers the same subject.';
        }

        return \sprintf(
            'unmeasured: %s:%d covers the same subject for the same rule, so removing this one alone proves nothing.',
            $maskedBy->file->value(),
            $maskedBy->line,
        );
    }

    /** The tag as the author typed it, which is what they will search for to remove it. */
    private static function tag(string $form): string
    {
        return match ($form) {
            'symbol' => '@qmx-ignore',
            'next-line' => '@qmx-ignore-next-line',
            'file' => '@qmx-ignore-file',
            'threshold' => '@qmx-threshold',
            default => '@qmx-' . $form,
        };
    }

}
