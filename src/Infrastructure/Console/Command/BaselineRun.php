<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Discovery\FinderFileDiscovery;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Infrastructure\Console\MeasuredViolationSet;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the analysis a baseline command measures against.
 *
 * **The set comes from configuration, never from this command's flags**
 * (§5.5 of the baseline-ceiling plan). None of the five commands declares
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
        private ConfigurationPipeline $configurationPipeline,
        private RuntimeConfigurator $runtimeConfigurator,
        private MeasuredViolationSet $measuredViolationSet,
        private ConfigurationProviderInterface $configurationProvider,
    ) {}

    public function measure(InputInterface $input, OutputInterface $output): BaselineRunContext
    {
        $resolved = $this->resolveConfiguration($input);

        // The same per-run setup `check` performs: memory limit, logger,
        // progress reporter, rule options, feature lifecycle hooks. Without
        // it the analysis below runs under defaults that `check` never uses,
        // and the two would measure different sets on the same project.
        $this->runtimeConfigurator->configure($resolved, $input, $output);

        $cwd = AbsolutePath::fromString((string) getcwd());
        $paths = array_map(
            static fn(string $raw): AbsolutePath => PathFactory::fromCliArgument($raw, $cwd),
            $resolved->paths->paths,
        );

        $this->assertPathsExist($paths);

        $run = $this->measuredViolationSet->runForPaths($paths, new FinderFileDiscovery($resolved->paths->excludes));
        $projectRoot = $this->configurationProvider->getConfiguration()->projectRoot;

        return new BaselineRunContext($run, self::portableScope($paths, $projectRoot), $projectRoot);
    }

    /**
     * @throws ConfigLoadException
     */
    private function resolveConfiguration(InputInterface $input): ResolvedConfiguration
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

    /**
     * Records the scope project-relatively where possible, so a baseline
     * committed by one developer means the same thing in another checkout.
     * A path outside the project root has no relative form and is kept as
     * given.
     *
     * @param list<AbsolutePath> $paths
     *
     * @return list<string>
     */
    private static function portableScope(array $paths, AbsolutePath $projectRoot): array
    {
        $scope = [];

        foreach ($paths as $path) {
            $relative = PathFactory::tryProjectRelative($path->value(), $projectRoot);
            $scope[] = $relative?->value() ?? $path->value();
        }

        return $scope;
    }
}
