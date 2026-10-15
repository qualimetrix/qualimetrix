<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;

use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Cache\Root\CacheLocationJudgement;
use Qualimetrix\Infrastructure\Cache\Root\CacheRootUntrusted;
use Qualimetrix\Infrastructure\Cache\Root\CacheSource;
use Qualimetrix\Infrastructure\Cache\Root\DefaultCacheDirectory;

final class CacheConfigurationResolver implements CacheConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document, AbsolutePath $projectRoot): CacheConfiguration
    {
        $configured = $document->resolved()->get(CacheSection::KEY, 'dir');
        $enabled = CacheSection::enabled($document->resolved()->get(CacheSection::KEY, 'enabled'));
        if ($configured === null) {
            return new CacheConfiguration(DefaultCacheDirectory::for(getenv(), \PHP_OS_FAMILY), $enabled, source: CacheSource::default(), projectRoot: $projectRoot);
        }
        $path = PathFactory::fromCliArgument(CacheSection::acceptedDirectory($configured), $projectRoot);
        $writers = $configured->contributors();
        $winner = $writers[\count($writers) - 1];
        $source = CacheSource::explicit($winner);
        if ($enabled) {
            $this->judgeExplicitDirectory($configured, $path, $projectRoot, $source);
        }

        return new CacheConfiguration($path, $enabled, source: $source, projectRoot: $projectRoot);
    }

    private function judgeExplicitDirectory(ResolvedValueInterface $configured, AbsolutePath $path, AbsolutePath $projectRoot, CacheSource $source): void
    {
        $reason = CacheLocationJudgement::reason($path, $projectRoot);
        if ($reason !== null) {
            $configured->refuse($reason);
        }
        $trust = CacheLocationJudgement::trustReason($path);
        if ($trust !== null) {
            throw new CacheRootUntrusted($source->spelling . ': ' . $trust);
        }
    }
}
