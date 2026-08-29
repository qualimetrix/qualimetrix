<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\IncompleteAnalysisException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\MeasuredFindingSet;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the analysis a baseline command measures against.
 *
 * **The set comes from configuration, never from this command's flags**
 * (ADR 0017). None of the five commands declares
 * `--exclude-path`, `--exclude-namespace` or `--no-suppression-annotations`,
 * so there is nothing here to read them from: exclusions arrive through
 * `qmx.yaml` and suppression through the source's own annotations, which is
 * precisely what makes a baseline command and `check` measure one set instead
 * of two that agree only when the same flags were typed twice.
 *
 * The steps are `check`'s own, in `check`'s order — resolve the
 * configuration, configure the runtime from it, discover under
 * `paths.excludes` — because any divergence here would move the set for one
 * side only. {@see MeasuredFindingSet} then applies the stages that define
 * the set itself.
 */
final readonly class BaselineRun implements BaselineRunInterface
{
    public function __construct(
        private RuntimeConfigurator $runtimeConfigurator,
        private MeasuredFindingSet $measuredFindingSet,
        private RuleInputValidator $ruleInputValidator,
        private ConfigurationInputAdapter $configurationInputAdapter,
        private RunConfigurationResolverInterface $runConfigurationResolver,
        private ConfiguredFindingExclusionsResolverInterface $findingExclusionsResolver,
        private CacheConfigurationResolverInterface $cacheConfigurationResolver,
        private ParallelConfigurationResolverInterface $parallelConfigurationResolver,
    ) {}

    public function measure(InputInterface $input, OutputInterface $output): BaselineRunContext
    {
        $this->runtimeConfigurator->resetRunState();
        $document = $this->configurationInputAdapter->resolve($input);
        $configuration = $this->runConfigurationResolver->resolve($document);
        $cacheConfiguration = $this->cacheConfigurationResolver->resolve($document, $configuration->projectRoot);
        $parallelConfiguration = $this->parallelConfigurationResolver->resolve($document);
        $findingConfiguration = $this->ruleInputValidator->resolve($document, $input);
        $exclusions = $this->findingExclusionsResolver->resolve($document);

        // The same per-run setup `check` performs: memory limit, logger,
        // progress reporter, rule options, feature lifecycle hooks. Without
        // it the analysis below runs under defaults that `check` never uses,
        // and the two would measure different sets on the same project.
        $this->runtimeConfigurator->configure(
            $document,
            $findingConfiguration,
            $cacheConfiguration,
            $parallelConfiguration,
            $input,
            $output,
        );
        $this->assertPathsExist($configuration->paths);

        $run = $this->measuredFindingSet->run(
            $configuration,
            null,
            new FindingProjectionOptions(
                excludePaths: $exclusions->excludePaths,
                excludeNamespaces: $exclusions->excludeNamespaces,
            ),
        );

        // A partial measured set is not evidence about what disappeared or
        // improved. Stop before deriving a claimed scope or letting any
        // lifecycle command interpret, report candidates from, or mutate a
        // baseline. --force only overrides the recorded-scope guard; it must
        // never turn analysis failure into accepted state.
        if (!$run->result->coverage->isComplete()) {
            throw new IncompleteAnalysisException($run->result->coverage);
        }

        $projectRoot = $configuration->projectRoot;

        return new BaselineRunContext($run, RunScope::record($configuration->paths, $projectRoot), $projectRoot);
    }

    /**
     * A path that does not exist would silently measure nothing, and a
     * baseline captured from nothing is indistinguishable from a project with
     * no findings — the one file state that must never be written by
     * accident.
     *
     * @param list<AbsolutePath> $paths
     *
     * @throws InvalidArgumentException
     */
    private function assertPathsExist(array $paths): void
    {
        $missing = [];

        foreach ($paths as $path) {
            if (!$path->exists()) {
                $missing[] = $path->value();
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(\sprintf(
                'Path(s) do not exist: %s',
                implode(', ', $missing),
            ));
        }
    }

}
