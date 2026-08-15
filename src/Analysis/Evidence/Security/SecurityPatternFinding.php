<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Exact subject and occurrence projection for one security-pattern collector entry.
 *
 * @internal
 *
 * @phpstan-type SecurityPatternEntry array<string, scalar>
 */
final readonly class SecurityPatternFinding
{
    private function __construct(
        private Location $location,
        private MetricSubject $subject,
        private string $superglobal,
    ) {}

    /**
     * @param SecurityPatternEntry $entry
     */
    public static function fromEntry(array $entry, RelativePath $file): self
    {
        $subject = MetricSubjectCodec::decodeEntry($entry, $file);
        $location = new Location($file, (int) ($entry['line'] ?? 0), precise: true);

        return new self(
            location: $location,
            subject: $subject,
            superglobal: (string) ($entry['superglobal'] ?? ''),
        );
    }

    public function toViolation(
        SymbolPath $fileSymbol,
        string $ruleName,
        string $patternType,
        Severity $severity,
        string $messageTemplate,
        ?string $recommendation,
    ): Violation {
        $suffix = $this->superglobal === '' ? '' : \sprintf(' ($%s)', $this->superglobal);
        $occurrenceKey = OccurrenceKey::semantic($patternType, [
            'type' => $patternType,
            'superglobal' => $this->superglobal,
        ]);

        return new Violation(
            location: $this->location,
            subject: $this->subject,
            symbolPath: $fileSymbol,
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: $messageTemplate . $suffix,
            severity: $severity,
            metricValue: 1.0,
            recommendation: $recommendation,
            occurrenceKey: $occurrenceKey,
        );
    }
}
