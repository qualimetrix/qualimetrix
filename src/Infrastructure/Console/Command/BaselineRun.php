<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Pipeline\IncompleteAnalysisException;
use Qualimetrix\Infrastructure\Console\AnalysisInputPathValidator;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\MeasuredFindingSet;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RunConfigurationPreparation;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\Formatter\CoverageNarrator;
use Qualimetrix\Reporting\ReportCoverage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the analysis a baseline command measures against.
 *
 * **The set comes from configuration, never from this command's flags**
 * (ADR 0017). None of the five commands declares
 * `--suppress-path`, `--suppress-namespace` or `--no-suppression-annotations`,
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
        private RunConfigurationPreparation $runConfigurationPreparation,
        private ConfiguredFindingExclusionsResolverInterface $findingExclusionsResolver,
        private ErrorStream $errorStream = new ErrorStream(),
    ) {}

    public function measure(InputInterface $input, OutputInterface $output): BaselineRunContext
    {
        $this->runtimeConfigurator->resetRunState();
        $document = $this->configurationInputAdapter->resolve($input);
        $runConfiguration = $this->runConfigurationPreparation->resolve($document);
        $configuration = $runConfiguration->runConfiguration;
        $findingConfiguration = $this->ruleInputValidator->resolve($document, $input);
        $exclusions = $this->findingExclusionsResolver->resolve($document);

        // The same per-run setup `check` performs: memory limit, logger,
        // progress reporter, rule options, feature lifecycle hooks. Without
        // it the analysis below runs under defaults that `check` never uses,
        // and the two would measure different sets on the same project.
        $this->runtimeConfigurator->configure(
            $document,
            $runConfiguration,
            $findingConfiguration,
            $input,
            $output,
        );
        $this->configurationInputAdapter->writeDiagnostics($document, $output, $findingConfiguration->diagnostics);
        (new AnalysisInputPathValidator())->validate($configuration->paths, $document);

        $run = $this->measuredFindingSet->run(
            $configuration,
            new FindingProjectionOptions(
                suppressPaths: $exclusions->suppressPaths,
                suppressNamespaces: $exclusions->suppressNamespaces,
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

        $coverage = $run->result->coverage;
        if ($coverage->isIntentionallyEmpty()) {
            $this->errorStream->write($output, CoverageNarrator::describe(new ReportCoverage(
                $coverage->discoveredFiles(),
                $coverage->analyzedFilesCount(),
                $coverage->generatedExcludedFilesCount(),
                $coverage->failedFilesCount(),
                excluded: $coverage->excludedCount(),
            )));
        }

        $projectRoot = $configuration->projectRoot;

        return new BaselineRunContext($run, RunScope::record($configuration->paths, $projectRoot), $projectRoot);
    }

}
