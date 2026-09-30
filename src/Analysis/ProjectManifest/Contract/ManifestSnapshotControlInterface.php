<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\ProjectManifest\Contract;

/** The Console entry owns this boundary; reads never reset themselves. */
interface ManifestSnapshotControlInterface
{
    public function beginInvocation(): void;

    /** @return list<ManifestIssue> Already observed causes, without IO or lazy loading. */
    public function observedIssues(): array;
}
