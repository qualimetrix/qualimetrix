<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Ast;

use Psr\Log\NullLogger;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\FileCache;

/**
 * The same parser {@see FileParserFactory} composes, for a process with no container.
 *
 * A parallel worker builds its own services, and this is the half of that job
 * that is about parsing. It lives here and not beside the worker because it is
 * the same subject as the factory above it — assembling a parser — and because
 * a worker that assembled one itself would drag the whole cache vocabulary into
 * a namespace whose subject is parallelism.
 */
final class WorkerParserFactory
{
    /**
     * A null directory means the run asked for no caching, so the answer is the
     * bare parser: the strategy resolved "is caching on?" before the worker was
     * started, and the worker states the answer it was given rather than
     * re-deriving it from defaults.
     */
    public static function create(?AbsolutePath $cacheDirectory): FileParserInterface
    {
        // Both loggers are written out rather than defaulted, because a worker
        // is the one place where silence is the answer and an omitted argument
        // cannot say so. A worker's own STDERR is not a channel the user reads,
        // and neither message would be news there: a file the parser refuses is
        // published as a `parse` entry in the run's coverage (measured under
        // `--workers=4`), and the key generator's verdict on the parser version
        // is the same in every process of one install, so the parent's single
        // warning already carries it.
        $parser = new PhpFileParser(logger: new NullLogger());

        if ($cacheDirectory === null) {
            return $parser;
        }

        $configurationStore = new CacheConfigurationStore();
        $configurationStore->replace(new CacheConfiguration($cacheDirectory, true));

        return new CachedFileParser(
            $parser,
            new FileCache($cacheDirectory),
            new CacheKeyGenerator(new NullLogger()),
            $configurationStore,
        );
    }
}
