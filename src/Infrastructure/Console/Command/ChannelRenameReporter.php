<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameReport;
use Qualimetrix\Core\ProductIdentity;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders a `baseline:rename-channels` success outcome — a
 * {@see ChannelRenameReport} — in the caller's chosen format.
 *
 * Mirrors {@see BaselineCaptureReporter}: the command resolves and validates
 * `--format` itself, this class only renders what the command already
 * decided to report. A refusal is no longer this class's concern: it is the
 * shared {@see BaselineCommand} ladder's, via
 * {@see \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal}
 * and {@see \Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter} — the
 * same `{error, exit_code}` envelope every other machine-readable refusal in
 * this tool uses, rather than this class's own ad hoc `{"error": ...}` shape.
 */
final class ChannelRenameReporter
{
    public static function report(ChannelRenameReport $report, string $format, OutputInterface $output): void
    {
        if ($format === 'json') {
            self::reportAsJson($report, $output);
        } else {
            self::reportAsText($report, $output);
        }
    }

    private static function reportAsText(ChannelRenameReport $report, OutputInterface $output): void
    {
        $output->writeln($report->written
            ? \sprintf(
                '<info>Carried %d of %d entries onto a new channel name.</info>',
                $report->renamedEntries,
                $report->totalEntries,
            )
            : \sprintf(
                '<info>No entry of the %d in this baseline matched the map; the file is unchanged.</info>',
                $report->totalEntries,
            ));

        foreach ($report->idleRows() as $old) {
            $output->writeln(\sprintf('<comment>Declared rename of "%s" matched no entry.</comment>', $old));
        }

        // "Carried, not dropped" rather than "carried unchanged": only entries
        // with a readable channel (a malformed occurrence/edge, or an
        // already-duplicate identity) are renamed here; an entry that is not
        // an object, has no readable channel, or sits in a non-array block
        // has no channel for the map to act on and passes through unchanged.
        foreach ($report->unreadable as $reason => $count) {
            $output->writeln(\sprintf(
                '<comment>%d entr%s carried rather than dropped, unread by this build, because %s.</comment>',
                $count,
                $count === 1 ? 'y was' : 'ies were',
                $reason,
            ));
        }
    }

    private static function reportAsJson(ChannelRenameReport $report, OutputInterface $output): void
    {
        $output->writeln(json_encode([
            'meta' => ProductIdentity::meta(gmdate('c')),
            'written' => $report->written,
            'entries' => $report->totalEntries,
            'renamed' => $report->renamedEntries,
            'rows' => $report->rowHits,
            'idle_rows' => $report->idleRows(),
            'unreadable' => $report->unreadable,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT));
    }
}
