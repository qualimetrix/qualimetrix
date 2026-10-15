<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CacheLifecycle;

use Qualimetrix\Infrastructure\Cache\CacheClearOutcome;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Symfony\Component\Console\Input\InputInterface;

final class CacheRunLifecycle
{
    private bool $runFinished = false;

    public function __construct(private readonly CacheFactory $cacheFactory) {}

    public function reset(): void
    {
        $this->runFinished = false;
        $this->cacheFactory->resetConfiguration();
    }

    public function clearCacheIfRequested(InputInterface $input): bool
    {
        if (!$input->hasOption('clear-cache') || $input->getOption('clear-cache') !== true) {
            return false;
        }

        $outcome = $this->cacheFactory->clear();
        if ($outcome === null) {
            return false;
        }
        self::refuseIncompleteCacheClear($outcome);

        return true;
    }

    public function finishRun(): void
    {
        if ($this->runFinished) {
            return;
        }
        $this->runFinished = true;
        $this->cacheFactory->reportDiscardedEntries();
    }

    private static function refuseIncompleteCacheClear(CacheClearOutcome $outcome): void
    {
        if ($outcome->complete) {
            return;
        }

        throw EnvironmentRefusal::aboutFile(
            $outcome->directory,
            'clear cache in',
            \sprintf('%d cache entries remain: %s', $outcome->remaining, $outcome->reason ?? 'unknown reason'),
        );
    }
}
