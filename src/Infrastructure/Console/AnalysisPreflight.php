<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
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
        private RunConfigurationPreparation $runConfigurationPreparation,
        private RuleInputValidator $ruleInputValidator,
        private FileDiscoveryFactoryInterface $fileDiscoveryFactory,
        private AnalysisInputPathValidator $pathValidator = new AnalysisInputPathValidator(),
    ) {}

    public function resolve(InputInterface $input, OutputInterface $output, ?AnalysisPreflightProfile $profile = null): PreparedAnalysisInput
    {
        $profile ??= AnalysisPreflightProfile::analysis();
        $this->runtimeConfigurator->resetRunState();

        $document = $this->configurationInputAdapter->resolve($input, $profile);
        $run = $this->runConfigurationPreparation->resolve($document);
        $runConfiguration = $run->runConfiguration;
        $this->pathValidator->validate($runConfiguration->paths, $document);
        $findingConfiguration = $profile->requiresFindingConfiguration
            ? $this->ruleInputValidator->resolve($document, $input)
            : null;

        $this->runtimeConfigurator->configure(
            $document,
            $run,
            $findingConfiguration,
            $input,
            $output,
            $profile,
        );
        $this->configurationInputAdapter->writeDiagnostics($document, $output, $findingConfiguration->diagnostics ?? []);

        return new PreparedAnalysisInput(
            $runConfiguration,
            $findingConfiguration,
            $this->fileDiscoveryFactory->create($runConfiguration->projectRoot, $runConfiguration->pathExcludes),
        );
    }

}
