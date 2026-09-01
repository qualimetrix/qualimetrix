<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport;

/**
 * The two projections of one directive audit.
 *
 * They live in one class because they must agree: a reader who runs the command
 * twice with different `--format` values is looking at one measurement, and two
 * renderers would be two chances to disagree about what a verdict said.
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
    /**
     * Built per report rather than injected as a service. The three values are
     * one measurement and its context; threading them through every call was
     * three chances to render one report's verdicts beside another run's
     * selection.
     *
     * @param list<string> $only
     * @param list<string> $disabled
     */
    public function __construct(
        private DirectiveAuditReport $report,
        private array $only,
        private array $disabled,
    ) {}

    public function text(): string
    {
        $report = $this->report;

        $lines = ['Directive audit', ''];

        $lines[] = \sprintf(
            '  Scope        %d file(s) analysed, %d finding(s) produced',
            $report->coverage->analyzedFilesCount(),
            $report->producedFindings,
        );
        if ($report->coverage->generatedExcludedFilesCount() > 0) {
            // A narrowed scope is the one thing that turns a live verdict dead,
            // so the narrowing is stated rather than left to be inferred from a
            // count that looks smaller than the tree.
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
        foreach ($this->selectionLines() as $line) {
            $lines[] = $line;
        }
        $lines[] = '';

        if ($report->verdicts === []) {
            $lines[] = '  No inline directives in the analysed scope.';
            $lines[] = '';

            return implode("\n", $lines) . "\n";
        }

        foreach ($report->verdicts as $verdict) {
            $lines[] = \sprintf(
                '  %s:%d  %s %s',
                $verdict->site->file->value(),
                $verdict->site->line,
                self::tag($verdict->site->form),
                $verdict->site->target,
            );
            foreach ($this->statement($verdict) as $sentence) {
                $lines[] = '      ' . $sentence;
            }
        }

        $lines[] = '';
        $lines[] = '  ' . $this->summaryLine();
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    public function json(int $exitCode): string
    {
        $report = $this->report;
        $counts = self::counts($report);

        return self::encode([
            'scope' => [
                'analyzed_files' => $report->coverage->analyzedFilesCount(),
                'generated_excluded_files' => $report->coverage->generatedExcludedFilesCount(),
                'failed_files' => $report->coverage->failedFilesCount(),
                'complete' => $report->coverage->isComplete(),
                'produced_findings' => $report->producedFindings,
            ],
            'selection' => ['only' => $this->only, 'disabled' => $this->disabled],
            'directives' => array_map(self::verdictToArray(...), $report->verdicts),
            'summary' => [
                'total' => \count($report->verdicts),
                'effective' => $counts[DirectiveEffect::Effective->value],
                'overrun' => $counts[DirectiveEffect::Overrun->value],
                'inert' => $counts[DirectiveEffect::Inert->value],
                'unmeasured' => $counts[DirectiveEffect::Unmeasured->value],
            ],
            'exit_code' => $exitCode,
        ]);
    }

    /**
     * The error shape `debug:layer-assignment` established, so one parser reads
     * both. Static because an error is reported before there is a report to
     * hold — a configuration that failed to resolve produced no measurement.
     */
    public static function jsonError(string $message, int $exitCode): string
    {
        return self::encode(['error' => $message, 'exit_code' => $exitCode]);
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
            DirectiveUnmeasurableReason::AlreadyRefused
                => 'unmeasured: the directive addresses nothing this run could apply it to; '
                    . '`annotation.unresolved-directive` answers it.',
            DirectiveUnmeasurableReason::AddressesEveryChannel
                => 'unmeasured: it carries no rule filter, so there is no producer to consult.',
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

    private function summaryLine(): string
    {
        $report = $this->report;
        $counts = self::counts($report);

        return \sprintf(
            '%d directive(s): %d effective, %d applied-boundary-only, %d inert, %d unmeasured',
            \count($report->verdicts),
            $counts[DirectiveEffect::Effective->value],
            $counts[DirectiveEffect::Overrun->value],
            $counts[DirectiveEffect::Inert->value],
            $counts[DirectiveEffect::Unmeasured->value],
        );
    }

    /** @return array<string, int> */
    private static function counts(DirectiveAuditReport $report): array
    {
        $counts = [];
        foreach (DirectiveEffect::cases() as $effect) {
            $counts[$effect->value] = 0;
        }
        foreach ($report->verdicts as $verdict) {
            ++$counts[$verdict->effect->value];
        }

        return $counts;
    }

    /**
     * @return array{
     *     file: string, line: int, form: string, target: string, effect: string,
     *     reason: ?string, masked_by: ?array{file: string, line: int}, boundary_observable: bool
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
        ];
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

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload): string
    {
        return json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }
}
