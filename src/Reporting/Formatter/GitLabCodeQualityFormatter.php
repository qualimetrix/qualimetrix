<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
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

        foreach ($report->violations as $violation) {
            $issues[] = [
                // The Code Climate spec has no field for the accepted level, so
                // a measured breach (ADR 0017) carries it in the free-text
                // description — the fingerprint below still hashes the
                // unmodified $violation->message, so it stays stable across
                // the run where a breach first appears.
                'description' => $violation->message . $this->formatBreachSuffix($violation),
                'check_name' => $violation->violationCode,
                'fingerprint' => $this->generateFingerprint($violation),
                'severity' => $this->mapSeverity($violation->severity),
                'location' => [
                    'path' => $violation->location->file === null
                        ? '_project'
                        : $context->relativizePath($violation->location->file),
                    'lines' => [
                        'begin' => $violation->location->file === null ? 1 : ($violation->location->line ?? 1),
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
    private function generateFingerprint(Violation $violation): string
    {
        return md5($violation->getFingerprint());
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
    private function formatBreachSuffix(Violation $violation): string
    {
        $breach = AcceptedLevelNarrator::describe($violation);

        return $breach === null ? '' : \sprintf(' (%s)', $breach);
    }
}
