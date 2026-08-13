<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationContext;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\IncompleteAnalysisException;
use Qualimetrix\Baseline\RunScope;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Infrastructure\Console\MeasuredViolationSet;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
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
 * side only. {@see MeasuredViolationSet} then applies the stages that define
 * the set itself.
 */
final readonly class BaselineRun implements BaselineRunInterface
{
    public function __construct(
        private ConfigurationPipelineInterface $configurationPipeline,
        private RuntimeConfigurator $runtimeConfigurator,
        private MeasuredViolationSet $measuredViolationSet,
        private TransitionalRuntimeConfigurationProviderInterface $configurationProvider,
        private RuleInputValidator $ruleInputValidator,
        private FileDiscoveryFactoryInterface $fileDiscoveryFactory,
    ) {}

    public function measure(InputInterface $input, OutputInterface $output): BaselineRunContext
    {
        $resolved = $this->resolveConfiguration($input);

        // The same per-run setup `check` performs: memory limit, logger,
        // progress reporter, rule options, feature lifecycle hooks. Without
        // it the analysis below runs under defaults that `check` never uses,
        // and the two would measure different sets on the same project.
        $this->runtimeConfigurator->configure($resolved, $input, $output);
        $this->ruleInputValidator->validate($resolved, $input);

        $cwd = AbsolutePath::fromString((string) getcwd());
        $paths = array_map(
            static fn(string $raw): AbsolutePath => PathFactory::fromCliArgument($raw, $cwd),
            $resolved->paths,
        );

        $this->assertPathsExist($paths);

        $run = $this->measuredViolationSet->runForPaths($paths, $this->fileDiscoveryFactory->create($resolved->pathExcludes));

        // A partial measured set is not evidence about what disappeared or
        // improved. Stop before deriving a claimed scope or letting any
        // lifecycle command interpret, report candidates from, or mutate a
        // baseline. --force only overrides the recorded-scope guard; it must
        // never turn analysis failure into accepted state.
        if (!$run->result->coverage->isComplete()) {
            throw new IncompleteAnalysisException($run->result->coverage);
        }

        $projectRoot = $this->configurationProvider->getConfiguration()->projectRoot;

        return new BaselineRunContext($run, RunScope::record($paths, $projectRoot), $projectRoot);
    }

    /**
     * @throws ConfigLoadException
     */
    private function resolveConfiguration(InputInterface $input): TransitionalResolvedConfiguration
    {
        /** @var string|null $configPath */
        $configPath = $input->getOption('config');
        $cwd = getcwd();

        if ($configPath !== null && $configPath !== '' && !file_exists($configPath)) {
            throw ConfigLoadException::fileNotFound($configPath);
        }

        return $this->configurationPipeline->resolve(new ConfigurationContext(
            $input,
            $cwd !== false ? $cwd : '.',
            $configPath !== null && $configPath !== '' ? $configPath : null,
        ));
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
