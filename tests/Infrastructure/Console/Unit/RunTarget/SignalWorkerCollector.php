<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\RunTarget;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ParallelSafeCollectorInterface;
use SplFileInfo;

/** Holds a real worker in collection while the coordinator receives a signal. */
final class SignalWorkerCollector implements MetricCollectorInterface, ParallelSafeCollectorInterface
{
    public function getName(): string
    {
        return 'signal-worker';
    }

    public function provides(): array
    {
        return [];
    }

    public function getMetricDefinitions(): array
    {
        return [];
    }

    public function getVisitor(): NodeVisitorAbstract
    {
        return new class extends NodeVisitorAbstract {};
    }

    /** @param Node[] $ast */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        file_put_contents($file->getPath() . '/' . $file->getBasename() . '.ready', (string) getmypid());
        $release = $file->getPath() . '/release-workers';
        $deadline = microtime(true) + 8;
        while (!is_file($release) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        return new MetricBag();
    }

    public function reset(): void {}
}
