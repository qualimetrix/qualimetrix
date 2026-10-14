<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Cache\Contract\CacheOpener;

final class CacheFactory implements CacheOpener
{
    private ?FileCache $cache = null;
    private bool $opened = false;
    private ?AbsolutePath $directory = null;
    private bool $discardedReported = false;
    private bool $locationJudged = false;
    private ?AbsolutePath $location = null;
    private LoggerInterface $logger;

    public function __construct(private readonly CacheConfigurationStoreInterface $configurationStore, private readonly CacheKeyGenerator $keyGenerator = new CacheKeyGenerator())
    {
        $this->logger = new NullLogger();
    }

    public function configureLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function open(): ?AbsolutePath
    {
        if ($this->opened) {
            return $this->directory;
        }
        $configuration = $this->configurationStore->current();
        if (!$configuration->enabled) {
            $this->opened = true;
            if ($configuration->disabledBecause !== null) {
                $this->judgeLocation();
            }

            return null;
        }
        $directory = $this->judgeLocation();
        $this->opened = true;
        if ($directory === null) {
            return null;
        }
        if (!$this->keyGenerator->available()) {
            $this->logger->warning('AST caching is off for this run: the Composer runtime cannot name the installed nikic/php-parser.');

            return null;
        }
        $this->directory = $directory;
        try {
            $sweep = $this->create()->sweepStep(time(), SweepPolicy::default());
        } catch (CacheRootUntrusted $failure) {
            if ($configuration->source?->explicit === true) {
                throw $failure;
            }
            $this->directory = null;
            $this->logger->warning('AST caching is off for this run: ' . $failure->getMessage());

            return null;
        }
        $this->logger->debug('AST cache sweep', ['shards' => $sweep->shards, 'removed' => $sweep->removed, 'bytes' => $sweep->bytes, 'cursorFailure' => $sweep->cursorFailure]);

        return $this->directory;
    }

    public function clear(): ?CacheClearOutcome
    {
        $directory = $this->judgeLocation();

        return $directory === null ? null : (new CacheDirectoryContents($directory))->clear();
    }

    private function judgeLocation(): ?AbsolutePath
    {
        if ($this->locationJudged) {
            return $this->location;
        }
        $configuration = $this->configurationStore->current();
        $reason = $configuration->disabledBecause
            ?? CacheLocationJudgement::reason($configuration->directory, $configuration->projectRoot);
        if ($reason !== null) {
            return $this->refuseLocation($configuration, $reason);
        }
        $directory = $configuration->directory ?? throw new LogicException('An accepted cache location requires an absolute directory.');
        $trust = CacheLocationJudgement::trustReason($directory);
        if ($trust !== null) {
            return $this->refuseUntrustedLocation($configuration, $trust);
        }
        $this->locationJudged = true;

        return $this->location = $directory;
    }

    private function refuseLocation(CacheConfiguration $configuration, string $reason): null
    {
        if ($configuration->source?->explicit === true) {
            $configuration->source->refuse($reason);
        }

        return $this->disableLocation($reason);
    }

    private function refuseUntrustedLocation(CacheConfiguration $configuration, string $reason): null
    {
        if ($configuration->source?->explicit === true) {
            throw new CacheRootUntrusted($configuration->source->spelling . ': ' . $reason);
        }

        return $this->disableLocation($reason);
    }

    private function disableLocation(string $reason): null
    {
        $this->locationJudged = true;
        $this->logger->warning('AST caching is off for this run: ' . $reason);

        return null;
    }

    public function create(): FileCache
    {
        $directory = $this->directory ?? $this->configurationStore->current()->directory;
        if ($directory === null) {
            throw new LogicException('A disabled cache has no directory.');
        }

        return $this->cache ??= new FileCache($directory);
    }

    public function reportDiscardedEntries(): void
    {
        $count = $this->cache?->discardedEntryCount() ?? 0;
        if (!$this->discardedReported && $count > 0) {
            $this->discardedReported = true;
            $this->logger->warning(\sprintf('%d cache %s discarded because the AST entry was invalid.', $count, $count === 1 ? 'entry' : 'entries'));
        }
    }

    public function reset(): void
    {
        $this->cache = null;
        $this->opened = false;
        $this->directory = null;
        $this->discardedReported = false;
        $this->locationJudged = false;
        $this->location = null;
        $this->logger = new NullLogger();
    }

    public function replaceConfiguration(CacheConfiguration $configuration): void
    {
        $this->configurationStore->replace($configuration);
    }

    public function resetConfiguration(): void
    {
        $this->reset();
        $this->configurationStore->reset();
    }
}
