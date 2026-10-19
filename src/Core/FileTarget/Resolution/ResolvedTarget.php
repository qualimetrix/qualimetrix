<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget\Resolution;

use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\PrivateGroup\PrivateGroupMembership;

use Qualimetrix\Core\FileTarget\TargetKind;

use Qualimetrix\Core\Path\AbsolutePath;

/**
 * @qmx-threshold health.cohesion 40.0 -- Independent immutable observations or stateless declaration operations intentionally have no shared instance-field graph; this finite class boundary keeps a further cohesion loss visible.
 */
final readonly class ResolvedTarget
{
    /** @var list<array{path: string, identity: FileIdentity}> */
    public array $directories;

    /** @var list<PathExposure> */
    public array $exposure;

    public bool $streamExposed;

    public function __construct(
        public string $spelling,
        public TargetKind $kind,
        public ?AbsolutePath $path,
        public ?int $descriptor,
        public ?FileIdentity $identity,
        private PathInspection $inspection,
        private PrivateGroupMembership $membership,
    ) {
        $this->directories = $inspection->directories;
        $this->exposure = $inspection->exposure;
        $this->streamExposed = $inspection->streamExposed;
    }

    public function membership(): PrivateGroupMembership
    {
        return $this->membership;
    }

    public function sameAs(self $other): bool
    {
        return $this->sameLocationAs($other)
            && $this->sameIdentityAs($other)
            && $this->inspection->sameDirectoriesAs($other->inspection);
    }

    private function sameLocationAs(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->descriptor === $other->descriptor
            && $this->path?->value() === $other->path?->value();
    }

    private function sameIdentityAs(self $other): bool
    {
        if (($this->identity === null) !== ($other->identity === null)) {
            return false;
        }

        if ($this->identity === null) {
            return true;
        }

        return $other->identity !== null && $this->identity->sameAs($other->identity);
    }
}
