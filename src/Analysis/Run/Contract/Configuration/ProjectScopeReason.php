<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestIssue;

/** A cause carried by the measured run; auxiliary sources explain evidence degradation. */
final readonly class ProjectScopeReason
{
    /** @param array<string, string|int|bool|list<string>> $data */
    public function __construct(public ProjectScopeReasonKind $kind, public array $data) {}

    public static function manifest(ManifestIssue $issue, bool $auxiliary): self
    {
        return new self(ProjectScopeReasonKind::ManifestIssue, ['issueKind' => $issue->kind->value, 'source' => $issue->source, 'location' => $issue->location, 'detail' => $issue->detail, 'auxiliary' => $auxiliary]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, ...$this->data];
    }
}
