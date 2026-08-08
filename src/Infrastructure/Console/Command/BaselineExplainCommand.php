<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BoundaryExplanation;
use Qualimetrix\Baseline\BoundaryExplanationService;
use Qualimetrix\Baseline\BoundaryExplanationStatus;
use Qualimetrix\Baseline\EffectiveBoundary;
use Qualimetrix\Baseline\EffectiveBoundaryBaselineSource;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Violation\ViolationChannel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `baseline:explain` — prints the boundary in force for one symbol and where
 * each part of it comes from (ADR 0017).
 *
 * Three sources answer the same question and a user cannot see which is
 * deciding: the baseline accepted a level, `qmx.yaml` configured a threshold,
 * and an `@qmx-threshold` annotation may have moved it for this symbol alone.
 * All three are printed, and **an absent source is spelled differently from a
 * source whose value is zero** — "(none)" against "0" — because a threshold of
 * 0 and no threshold at all lead to opposite conclusions.
 *
 * On a channel whose scale can drift — `coupling.cbo` changes meaning with its
 * `scope` option, a computed metric's formula can be rewritten — the stored
 * number and the number being compared against it today are printed side by
 * side (ADR 0017). The divergence cannot be detected without storing the
 * configuration that produced the magnitude, so the least this command can do
 * is show both numbers where a user would look for them.
 *
 * It takes `<paths...>` because the annotation source is extracted during
 * Collection and cannot be read from configuration alone.
 */
#[AsCommand(
    name: 'baseline:explain',
    description: 'Show the effective boundary for a symbol and where it comes from',
)]
final class BaselineExplainCommand extends BaselineCommand
{
    public function __construct(
        private readonly BaselineRunInterface $baselineRun,
        private readonly BaselineLoader $loader,
        private readonly BoundaryExplanationService $explanationService,
        private readonly BaselineConfiguredThresholds $configuredThresholds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'symbol',
            InputArgument::REQUIRED,
            'Canonical symbol key, as printed in reports (e.g. method:App\\OrderService::calculate)',
        );

        BaselineCommandDefinition::addMeasuredRunInput($this);

        $this
            ->addOption(
                'baseline',
                null,
                InputOption::VALUE_REQUIRED,
                'Baseline file whose accepted levels should be taken into account',
            )
            ->addOption(
                'channel',
                null,
                InputOption::VALUE_REQUIRED,
                'Restrict the answer to one channel, in "rule-name#violation-code" form',
            )
            ->setHelp(
                'Prints, for every channel that either the baseline or the current run has'
                . "\n" . 'something to say about: the level the baseline accepted and what is'
                . "\n" . 'reported now, the threshold qmx.yaml configures, and any'
                . "\n" . '`@qmx-threshold` annotation covering the symbol.',
            );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $symbolKey */
        $symbolKey = $input->getArgument('symbol');

        $channel = $this->readChannel($input, $output);
        if ($channel === false) {
            return self::EXIT_INVALID_INPUT;
        }

        // The run first, then the file (ADR 0017). A `computed.*` / `health.*`
        // channel's declaration is resolved from configuration this run
        // resolves; a file read before it loads every such entry inert, and
        // `explain` would then deny the existence of an acceptance `check`
        // applies on the same file.
        $context = $this->baselineRun->measure($input, $output);
        $baseline = $this->readBaseline($input);

        $explanation = $this->explanationService->explain(
            $symbolKey,
            $channel,
            $baseline,
            $context->violations(),
            $context->result()->thresholdOverrides,
            $this->configuredThresholds->resolve(),
            $context->result()->metrics,
        );

        if ($explanation->status === BoundaryExplanationStatus::Unknown) {
            $output->writeln(\sprintf(
                '<error>Unknown symbol "%s": it is absent from both the current analysis and the baseline.</error>',
                $symbolKey,
            ));

            return self::EXIT_INVALID_INPUT;
        }

        self::render($explanation, $output);

        return self::SUCCESS;
    }

    private function readBaseline(InputInterface $input): ?Baseline
    {
        $path = $input->getOption('baseline');

        return \is_string($path) && $path !== '' ? $this->loader->load($path) : null;
    }

    /**
     * `false` when `--channel` was given but is not a channel key; `null`
     * when it was not given at all, which means "every channel".
     */
    private function readChannel(InputInterface $input, OutputInterface $output): ViolationChannel|false|null
    {
        $raw = $input->getOption('channel');

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return ViolationChannel::fromKey($raw);
        } catch (InvalidArgumentException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return false;
        }
    }

    private static function render(BoundaryExplanation $explanation, OutputInterface $output): void
    {
        $output->writeln(\sprintf('Symbol: <info>%s</info>', $explanation->symbolKey));

        if ($explanation->status === BoundaryExplanationStatus::BaselineOnly) {
            $output->writeln('  <comment>Baseline only: this symbol is absent from the current analysis scope or result.</comment>');
        }

        if ($explanation->boundaries === []) {
            $output->writeln('');
            $output->writeln($explanation->status === BoundaryExplanationStatus::BaselineOnly
                ? '  <comment>The baseline names this symbol, but no entry forms an applicable boundary.</comment>'
                : '  <comment>Nothing currently reports on this measured symbol.</comment>');

            return;
        }

        foreach ($explanation->boundaries as $boundary) {
            $output->writeln('');
            $output->writeln(\sprintf('  Channel: <info>%s</info>', $boundary->identity->channel->toKey()));

            if ($boundary->identity->edge !== null) {
                $output->writeln(\sprintf('    Edge: %s', $boundary->identity->edge->target));
            }

            $output->writeln(\sprintf('    baseline:      %s', self::describeBaseline($boundary->baseline)));
            $output->writeln(\sprintf('    qmx.yaml:      %s', self::describeConfigured($boundary)));
            $output->writeln(\sprintf('    annotation:    %s', self::describeAnnotation($boundary->annotation)));
        }
    }

    /**
     * Both numbers, always: the level the entry stores and the level being
     * compared against it in this run (ADR 0017).
     */
    private static function describeBaseline(?EffectiveBoundaryBaselineSource $source): string
    {
        if ($source === null) {
            return '(none)';
        }

        return \sprintf(
            'accepted %s; now %s',
            $source->accepted->describe(),
            self::describeCurrent($source),
        );
    }

    private static function describeCurrent(EffectiveBoundaryBaselineSource $source): string
    {
        if ($source->currentCount === 0) {
            return 'nothing reported';
        }

        if ($source->currentMagnitudes === null || $source->currentMagnitudes === []) {
            return $source->currentCount === 1 ? '1 occurrence' : $source->currentCount . ' occurrences';
        }

        return implode(', ', array_map(self::formatNumber(...), $source->currentMagnitudes));
    }

    private static function describeConfigured(EffectiveBoundary $boundary): string
    {
        return $boundary->configuredThreshold === null
            ? '(not resolvable from configuration)'
            : self::formatNumber($boundary->configuredThreshold);
    }

    private static function describeAnnotation(?ThresholdOverride $annotation): string
    {
        if ($annotation === null) {
            return '(none)';
        }

        return \sprintf(
            '@qmx-threshold %s warning=%s error=%s',
            $annotation->rulePattern,
            $annotation->warning === null ? '(unchanged)' : self::formatNumber($annotation->warning),
            $annotation->error === null ? '(unchanged)' : self::formatNumber($annotation->error),
        );
    }

    private static function formatNumber(int|float $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        $formatted = rtrim(rtrim(\sprintf('%.6F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
