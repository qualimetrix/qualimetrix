<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use LogicException;
use Qualimetrix\Core\Path\AbsolutePath;

/** A private sibling held until one complete document can be published. */
final class PreparedTarget
{
    private function __construct(
        private readonly ResolvedTarget $target,
        private readonly TemporarySibling $temporary,
        private readonly int $ownerPid,
    ) {}

    public static function prepare(ResolvedTarget $target): self
    {
        $ownerPid = getmypid();
        if ($ownerPid === false) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'cannot determine the prepared target owner process');
        }
        if ($target->kind !== TargetKind::Regular && $target->kind !== TargetKind::Absent) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'replacement requires a regular file or absent name');
        }
        $path = $target->path?->value() ?? throw new LogicException('Replacement path is missing');
        if (!$target->sameAs(TargetPath::resolve($target->spelling, $target->membership()))) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $target->spelling, 'target changed before preparation');
        }
        try {
            $temporary = TemporarySibling::create(AbsolutePath::fromString(\dirname($path)));
        } catch (FileTargetFailure $failure) {
            throw new FileTargetFailure(
                $failure->kind,
                $target->spelling,
                $failure->reason,
                $failure->spelling . ($failure->detail === '' ? '' : ': ' . $failure->detail),
            );
        }

        return new self($target, $temporary, $ownerPid);
    }

    public function target(): ResolvedTarget
    {
        return $this->target;
    }

    public function siblingPath(): string
    {
        return $this->temporary->path()->value();
    }

    /** @param ?callable(): void $beforePublish */
    public function publish(string $bytes, ?int $mode, NewName $newName, ?callable $beforePublish = null): void
    {
        if ($this->ownerPid !== getmypid()) {
            throw new FileTargetFailure(FileTargetFailureKind::IdentityChanged, $this->target->spelling, 'prepared target belongs to another process');
        }
        FileReplacement::publishPrepared($this->target, $this->temporary, $bytes, $mode, $newName, $beforePublish);
    }

    public function discard(): void
    {
        $this->temporary->discard();
    }
}
