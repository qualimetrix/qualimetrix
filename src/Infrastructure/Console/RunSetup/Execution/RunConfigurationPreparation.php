<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunSetup\Execution;

use Closure;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\NativeCall;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;

/** Resolves one run and its dependent runtime values before any stores commit. */
final readonly class RunConfigurationPreparation
{
    public function __construct(
        private RunConfigurationResolverInterface $runConfigurationResolver,
        private CacheConfigurationResolverInterface $cacheConfigurationResolver,
        private ParallelConfigurationResolverInterface $parallelConfigurationResolver,
        private ProjectFilesInterface $projectFiles,
    ) {}

    public function resolve(ConfigurationDocument $document): ResolvedRunConfiguration
    {
        $run = $this->runConfigurationResolver->resolve($document);
        $sourceReadAs = $this->sourceReadAs($run);

        return new ResolvedRunConfiguration(
            $run,
            $this->cacheConfigurationResolver->resolve($document, $run->projectRoot),
            $this->parallelConfigurationResolver->resolve($document),
            static fn(?FileIdentity $identity): ?string => $document->fileReadAs($identity) ?? $sourceReadAs($identity),
        );
    }

    /**
     * The run's files are walked once, when first asked about a regular file:
     * no other identity can be a source, so a run whose targets do not exist
     * yet walks nothing.
     *
     * @return Closure(?FileIdentity): ?string
     */
    private function sourceReadAs(RunConfiguration $run): Closure
    {
        $selected = null;
        $root = rtrim($run->projectScope->universe->projectRoot->value(), '/') . '/';

        return function (?FileIdentity $identity) use ($run, $root, &$selected): ?string {
            if ($identity === null || $identity->inode === 0 || !$identity->isRegularFile()) {
                return null;
            }
            $selected ??= $this->projectFiles->select($run);
            foreach ($selected as $file) {
                $path = $file->getPathname();
                [$stat] = NativeCall::attempt(static fn(): array|false => stat($path));
                if (\is_array($stat) && FileIdentity::fromStat($stat)->sameAs($identity)) {
                    return \sprintf('source file "%s"', str_starts_with($path, $root) ? substr($path, \strlen($root)) : $path);
                }
            }

            return null;
        };
    }
}
