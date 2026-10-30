<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestIssue;

/** A cause carried by the measured run; auxiliary sources explain evidence degradation. */
final readonly class ProjectScopeReason
{
    /** @param array<string, string|int|bool|list<string>> $data */
    public function __construct(public ProjectScopeReasonKind $kind, public array $data) {}

    public static function mainManifest(ManifestIssue $issue): self
    {
        return new self(ProjectScopeReasonKind::ManifestIssue, ['issueKind' => $issue->kind->value, 'source' => 'composer.json', 'location' => $issue->location, 'detail' => $issue->detail, 'auxiliary' => false]);
    }

    public static function auxiliaryManifest(ManifestIssue $issue): self
    {
        return new self(ProjectScopeReasonKind::ManifestIssue, ['issueKind' => $issue->kind->value, 'source' => $issue->source, 'location' => $issue->location, 'detail' => $issue->detail, 'auxiliary' => true]);
    }

    /** Set entries written only by an installed file are left unjudged; one reason per file. */
    public static function installedConfiguration(string $source): self
    {
        return new self(ProjectScopeReasonKind::InstalledConfiguration, [
            'source' => $source,
            'detail' => 'Set entries written only by a file an installed Composer package provides are not judged; the project can neither edit nor remove them.',
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, ...$this->data];
    }
}
