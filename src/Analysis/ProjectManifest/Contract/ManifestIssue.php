<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\ProjectManifest\Contract;

/** A source cause, independent of the policy a consumer applies to it. */
final readonly class ManifestIssue
{
    /** @param list<string> $location */
    public function __construct(
        public ManifestIssueKind $kind,
        public string $source,
        public array $location,
        public string $detail,
    ) {}

    /** @return array{kind: string, source: string, location: list<string>, detail: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'source' => $this->source, 'location' => $this->location, 'detail' => $this->detail];
    }
}
