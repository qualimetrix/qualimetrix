<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler;

use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileFormat;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSessionControlInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSummary;

final class ProfileSession implements ProfilerInterface, ProfileSessionControlInterface, ProfileReportInterface
{
    private bool $enabled = false;
    private Profiler $profiler;
    public function __construct()
    {
        $this->profiler = new Profiler();
    }
    public function enable(): void
    {
        $this->enabled = true;
        $this->profiler->clear();
    }
    public function disable(): void
    {
        $this->enabled = false;
        $this->profiler->clear();
    }
    public function start(string $name, ?string $category = null): void
    {
        if ($this->enabled) {
            $this->profiler->start($name, $category);
        }
    }
    public function stop(string $name): void
    {
        if ($this->enabled) {
            $this->profiler->stop($name);
        }
    }
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    public function summary(): ProfileSummary
    {
        return new ProfileSummary($this->enabled ? $this->profiler->getSummary() : []);
    }
    public function export(ProfileFormat $format): string
    {
        return $this->profiler->export($format->value);
    }
}
