<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler\Contract;

interface ProfileReportInterface
{
    public function isEnabled(): bool;
    public function summary(): ProfileSummary;
    public function export(ProfileFormat $format): string;
}
