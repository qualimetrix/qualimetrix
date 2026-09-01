<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Everything a command must settle before it may run an analysis: the
 * configuration document resolved into the run's values, and every runtime
 * store committed to them.
 *
 * It exists because the sequence is not optional and not obvious. Resolve the
 * document, derive the run, the cache, the parallelism and the rule
 * configuration from it, commit them, and only then measure — a command that
 * skips a step measures under a configuration the user did not ask for, and
 * nothing about the result says so.
 *
 * `CheckCommand` still runs its own copy of the sequence, and knowingly: it
 * interleaves git-scope resolution, finding exclusions and the output format
 * with these steps, so lifting it here would mean pulling three subjects that
 * only `check` has into a step every analysing command runs. Two copies is the
 * declared cost; a `check` that silently disagreed with this one would be a
 * defect, and the finding-equivalence gate is what would say so.
 *
 * **The discovery comes out of the same step as the configuration it belongs
 * to.** That is the point of returning it rather than letting each caller
 * build one: `AnalysisFileDiscovery` falls back to a default that knows nothing
 * of the user's `exclude`, so a command that forgets silently analyses a wider
 * tree than the project does.
 */
final readonly class AnalysisPreflight
{
    public function __construct(
        private RuntimeConfigurator $runtimeConfigurator,
        private ConfigurationInputAdapter $configurationInputAdapter,
        private RunConfigurationResolverInterface $runConfigurationResolver,
        private CacheConfigurationResolverInterface $cacheConfigurationResolver,
        private ParallelConfigurationResolverInterface $parallelConfigurationResolver,
        private RuleInputValidator $ruleInputValidator,
        private FileDiscoveryFactoryInterface $fileDiscoveryFactory,
    ) {}

    public function resolve(InputInterface $input, OutputInterface $output): PreparedAnalysisInput
    {
        $this->runtimeConfigurator->resetRunState();

        $document = $this->configurationInputAdapter->resolve($input);
        $runConfiguration = $this->runConfigurationResolver->resolve($document);
        $findingConfiguration = $this->ruleInputValidator->resolve($document, $input);

        $this->runtimeConfigurator->configure(
            $document,
            $findingConfiguration,
            $this->cacheConfigurationResolver->resolve($document, $runConfiguration->projectRoot),
            $this->parallelConfigurationResolver->resolve($document),
            $input,
            $output,
        );

        return new PreparedAnalysisInput(
            $runConfiguration,
            $findingConfiguration,
            $this->fileDiscoveryFactory->create($runConfiguration->pathExcludes),
        );
    }

    /**
     * The paths that do not exist, as messages. Empty when every path is
     * readable.
     *
     * @return list<string>
     */
    public static function missingPaths(RunConfiguration $configuration): array
    {
        $errors = [];

        foreach ($configuration->paths as $path) {
            if (!$path->exists()) {
                $errors[] = \sprintf("Error: path '%s' does not exist", $path->value());
            }
        }

        return $errors;
    }
}
