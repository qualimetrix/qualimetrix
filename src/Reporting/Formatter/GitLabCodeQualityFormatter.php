<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Reporting\Formatter\Support\AcceptedLevelNarrator;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * Formats report as GitLab Code Quality JSON.
 *
 * Spec: https://docs.gitlab.com/ee/ci/testing/code_quality.html#code-quality-report-format
 * Compatible with GitLab Merge Request Code Quality widget.
 */
final class GitLabCodeQualityFormatter implements FormatterInterface
{
    public function format(Report $report, FormatterContext $context): string
    {
        $issues = [];

        foreach ($report->findings as $finding) {
            $issues[] = [
                // The Code Climate spec has no field for the accepted level, so
                // a measured breach (ADR 0017) carries it in the free-text
                // description — the fingerprint below still hashes the
                // unmodified $finding->message, so it stays stable across
                // the run where a breach first appears.
                'description' => $finding->message . $this->formatBreachSuffix($finding),
                'check_name' => $finding->code,
                'fingerprint' => $this->generateFingerprint($finding),
                'severity' => $this->mapSeverity($finding->severity),
                'location' => [
                    'path' => $finding->location->file === null
                        ? '_project'
                        : $context->relativizePath($finding->location->file),
                    'lines' => [
                        'begin' => $finding->location->file === null ? 1 : ($finding->location->line ?? 1),
                    ],
                ],
            ];
        }

        foreach ($report->coverage === null ? [] : $report->coverage->failures as $failure) {
            $issues[] = [
                'description' => \sprintf('Analysis failed for %s: %s', $failure->path, $failure->message),
                'check_name' => 'analysis.' . $failure->kind,
                'fingerprint' => md5('analysis|' . $failure->kind . '|' . $failure->path),
                'severity' => 'blocker',
                'location' => ['path' => $failure->path, 'lines' => ['begin' => 1]],
            ];
        }

        return json_encode($issues, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function getName(): string
    {
        return 'gitlab';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    /**
     * Generates stable fingerprint for GitLab to track issues across MRs.
     *
     * Format: md5(channel|subject|occurrence|edge)
     *
     * Locations and messages are presentation data. They must not participate
     * in the identity GitLab uses to track a finding across revisions.
     */
    private function generateFingerprint(Finding $finding): string
    {
        return md5($finding->getFingerprint());
    }

    /**
     * Maps internal severity to GitLab Code Quality severity.
     *
     * GitLab severities: blocker, critical, major, minor, info
     */
    private function mapSeverity(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'critical',
            Severity::Warning => 'major',
            Severity::Info => 'info',
        };
    }

    /**
     * " (accepted at 25, now 31)" on a measured breach, '' otherwise (ADR 0017).
     */
    private function formatBreachSuffix(Finding $finding): string
    {
        $breach = AcceptedLevelNarrator::describe($finding);

        return $breach === null ? '' : \sprintf(' (%s)', $breach);
    }
}
