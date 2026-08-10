<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\OccurrenceKey;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

/**
 * Exact subject and occurrence projection for one code-smell collector entry.
 *
 * @internal
 *
 * @phpstan-type CodeSmellEntry array<string, scalar>
 */
final readonly class CodeSmellFinding
{
    private function __construct(
        private Location $location,
        private MetricSubject $subject,
        private string $extra,
        private bool $hasExtra,
        private bool $promoted,
        private bool $hasPromoted,
    ) {}

    /**
     * @param CodeSmellEntry $entry
     */
    public static function fromEntry(array $entry, RelativePath $file): self
    {
        return new self(
            location: new Location($file, (int) ($entry['line'] ?? 0), precise: true),
            subject: MetricSubjectCodec::decodeEntry($entry, $file),
            extra: (string) ($entry['extra'] ?? ''),
            hasExtra: \array_key_exists('extra', $entry),
            promoted: (bool) ($entry['promoted'] ?? false),
            hasPromoted: \array_key_exists('promoted', $entry),
        );
    }

    public function toViolation(
        SymbolPath $fileSymbol,
        string $ruleName,
        string $smellType,
        Severity $severity,
        string $message,
        ?string $recommendation,
    ): Violation {
        $occurrenceKey = OccurrenceKey::semantic($smellType, [
            'type' => $smellType,
            'extra' => $this->extra,
            'hasExtra' => $this->hasExtra,
            'promoted' => $this->promoted,
            'hasPromoted' => $this->hasPromoted,
        ]);

        return new Violation(
            location: $this->location,
            subject: $this->subject,
            symbolPath: $fileSymbol,
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: $message,
            severity: $severity,
            metricValue: 1.0,
            recommendation: $recommendation,
            occurrenceKey: $occurrenceKey,
        );
    }
}
