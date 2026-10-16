<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunTarget;

use LogicException;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Core\FileTarget\TargetPath;

/** The access verdict available before a console run opens its target. */
final class TargetAccess
{
    public static function inspect(string $spelling): ResolvedTarget
    {
        if (str_ends_with($spelling, '/')) {
            throw new FileTargetFailure(FileTargetFailureKind::Directory, $spelling, 'target is a directory');
        }

        $target = TargetPath::resolve($spelling);
        if ($target->kind === TargetKind::Descriptor) {
            self::assertDescriptorAccessible($target);
        } else {
            self::assertPathAccessible($target);
        }

        return $target;
    }

    private static function assertDescriptorAccessible(ResolvedTarget $target): void
    {
        $fd = $target->descriptor ?? throw new LogicException('Descriptor target has no descriptor');
        if (ProcessStreams::identity($fd) === null) {
            throw new FileTargetFailure(FileTargetFailureKind::ForeignDescriptor, $target->spelling, 'descriptor is not open in this process');
        }
        if (ProcessStreams::isWritable($fd) === false) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'descriptor is open only for reading');
        }
    }

    private static function assertPathAccessible(ResolvedTarget $target): void
    {
        $path = $target->path?->value() ?? throw new LogicException('Path target has no path');
        if ($target->kind === TargetKind::Absent) {
            self::assertParentCanCreate($target->spelling, $path);

            return;
        }

        if (!is_writable($path)) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $target->spelling, 'target is not writable');
        }
    }

    private static function assertParentCanCreate(string $spelling, string $path): void
    {
        $parent = \dirname($path);
        if (!is_writable($parent) || !is_executable($parent)) {
            throw new FileTargetFailure(FileTargetFailureKind::Unopenable, $spelling, 'parent directory cannot create the target', $parent);
        }
    }
}
