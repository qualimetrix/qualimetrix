<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\DrillDown;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Reporting\Formatter\PublishedFinding;

/** The exact finding identity omitted by a report selection. */
final readonly class OutOfScopeIdentity
{
    /** @param ?array{target: string, type?: string} $edge */
    public function __construct(
        public string $channel,
        public string $subject,
        public ?string $occurrence,
        public ?array $edge,
        public string $severity,
    ) {}

    public static function of(Finding $finding): self
    {
        return new self($finding->channel()->code, $finding->subject->toCanonical(), $finding->occurrenceKey?->value, PublishedFinding::edge($finding), $finding->severity->value);
    }

    /** @return array{channel: string, subject: string, occurrence: ?string, edge: ?array{target: string, type?: string}, severity: string} */
    public function published(): array
    {
        return ['channel' => $this->channel, 'subject' => $this->subject, 'occurrence' => $this->occurrence, 'edge' => $this->edge, 'severity' => $this->severity];
    }
}
