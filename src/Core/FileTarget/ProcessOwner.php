<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use Qualimetrix\Core\Path\AbsolutePath;
use Throwable;

final class ProcessOwner
{
    /** @var array<int, int> */
    private static array $byDevice = [];

    public static function effectiveUid(string $nearDirectory): int
    {
        if (\function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        $directory = @stat($nearDirectory);
        if ($directory === false) {
            throw new FileTargetFailure(FileTargetFailureKind::OwnerUnknown, $nearDirectory, 'cannot inspect the directory for an owner probe');
        }

        if (isset(self::$byDevice[$directory['dev']])) {
            return self::$byDevice[$directory['dev']];
        }

        $temporary = null;
        foreach (array_unique([$nearDirectory, sys_get_temp_dir()]) as $probeDirectory) {
            try {
                $temporary = TemporarySibling::create(AbsolutePath::fromString($probeDirectory));
                break;
            } catch (FileTargetFailure $failure) {
                if ($failure->kind !== FileTargetFailureKind::Unopenable) {
                    throw new FileTargetFailure(FileTargetFailureKind::OwnerUnknown, $nearDirectory, 'owner probe identity was unsafe', $failure->getMessage());
                }
            }
        }
        if ($temporary === null) {
            throw new FileTargetFailure(FileTargetFailureKind::OwnerUnknown, $nearDirectory, 'cannot create an owner probe');
        }

        try {
            try {
                $opened = fstat($temporary->handle());
                if ($opened === false) {
                    throw new FileTargetFailure(FileTargetFailureKind::OwnerUnknown, $nearDirectory, 'cannot inspect owner probe');
                }
                $uid = $opened['uid'];
            } finally {
                $temporary->discard();
            }

            return self::$byDevice[$directory['dev']] = $uid;
        } catch (Throwable $error) {
            throw new FileTargetFailure(FileTargetFailureKind::OwnerUnknown, $nearDirectory, 'cannot safely complete the owner probe', $error->getMessage());
        }
    }
}
