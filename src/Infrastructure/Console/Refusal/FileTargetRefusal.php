<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;

final class FileTargetRefusal
{
    public static function from(?string $subject, FileTargetFailure $failure): RefusalInterface
    {
        if (\in_array($failure->kind, [
            FileTargetFailureKind::UnsupportedScheme,
            FileTargetFailureKind::Directory,
            FileTargetFailureKind::DirectoryMissing,
            FileTargetFailureKind::ForeignDescriptor,
        ], true)) {
            return $subject === null
                ? ConfigurationRefusal::aboutResolvedInput($failure->getMessage())
                : ConfigurationRefusal::aboutCommandLineInput($subject, $failure->getMessage());
        }

        if (\in_array($failure->kind, [FileTargetFailureKind::IdentityChanged, FileTargetFailureKind::Appeared], true)) {
            $detail = $failure->reason . ($failure->detail === '' ? '' : ' (' . $failure->detail . ')');

            return EnvironmentRefusal::aboutContention($failure->spelling, $detail);
        }

        return EnvironmentRefusal::fromFailure($failure);
    }
}
