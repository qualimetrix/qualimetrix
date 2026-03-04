<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Report;

/**
 * Formats report as GitLab Code Quality JSON.
 *
 * Spec: https://docs.gitlab.com/ee/ci/testing/code_quality.html#code-quality-report-format
 * Compatible with GitLab Merge Request Code Quality widget.
 */
final class GitLabCodeQualityFormatter implements FormatterInterface
{
    public function format(Report $report): string
    {
        $issues = [];

        foreach ($report->violations as $violation) {
            $issues[] = [
                'description' => $violation->message,
                'check_name' => $violation->ruleName,
                'fingerprint' => $this->generateFingerprint($violation),
                'severity' => $this->mapSeverity($violation->severity),
                'location' => [
                    'path' => $violation->location->file,
                    'lines' => [
                        'begin' => $violation->location->line ?? 1,
                    ],
                ],
            ];
        }

        $json = json_encode($issues, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return $json;
    }

    public function getName(): string
    {
        return 'gitlab';
    }

    /**
     * Generates stable fingerprint for GitLab to track issues across MRs.
     *
     * Format: md5(rule|symbolPath|line)
     */
    private function generateFingerprint(Violation $violation): string
    {
        $parts = [
            $violation->ruleName,
            $violation->symbolPath->toCanonical(),
            (string) ($violation->location->line ?? 0),
        ];

        return md5(implode('|', $parts));
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
        };
    }
}
