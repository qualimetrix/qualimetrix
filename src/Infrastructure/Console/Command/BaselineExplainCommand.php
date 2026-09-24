<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationService;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationStatus;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `baseline:explain` — prints the boundary in force for one metric subject and where
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
    description: 'Show the effective boundary for a metric subject and where it comes from',
)]
final class BaselineExplainCommand extends BaselineCommand
{
    public function __construct(
        private readonly BaselineRunInterface $baselineRun,
        private readonly BaselineLoader $loader,
        private readonly BoundaryExplanationService $explanationService,
        private readonly BaselineConfiguredThresholds $configuredThresholds,
        private readonly ChannelDeclarationRegistryInterface $declarations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'subject',
            InputArgument::REQUIRED,
            'Canonical metric subject key, as printed in reports',
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
            ->setHelp(self::withDocsPointer(
                'Prints, for every channel that either the baseline or the current run has'
                . "\n" . 'something to say about: the level the baseline accepted and what is'
                . "\n" . 'reported now, the threshold qmx.yaml configures, and any'
                . "\n" . '`@qmx-threshold` annotation covering the symbol.',
            ));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $subjectKey = CommandLineSpelling::requiredArgument($input, 'subject');

        $channel = $this->readChannel($input);

        // The run first, then the file's contents (ADR 0017). A `computed.*` /
        // `health.*` channel's declaration is resolved from configuration this
        // run resolves; a file read before it loads every such entry inert,
        // and `explain` would then deny the existence of an acceptance `check`
        // applies on the same file. Whether the file exists needs no
        // declaration, so that alone is asked before the run.
        $baselinePath = self::baselinePath($input);
        if ($baselinePath !== null) {
            BaselineLoader::assertReadable($baselinePath);
        }

        $context = $this->baselineRun->measure($input, $output);
        $baseline = $baselinePath !== null ? $this->loader->load($baselinePath) : null;

        // Addressability is checked here, not in readChannel(): the registry
        // side needs the computed-metric definitions this run just resolved,
        // and a channel absent from the registry can still be legitimate —
        // baseline:rename-channels exists precisely because a file outlives a
        // rename, so a channel the registry no longer knows is a valid input
        // when the loaded baseline still carries it.
        if ($channel !== null && $this->declarations->declarationFor($channel) === null
            && !self::channelInBaseline($channel, $baseline)) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--channel',
                \sprintf(
                    'Channel "%s" is declared by no rule and appears in no entry of the loaded baseline.',
                    $channel->code,
                ),
            );
        }

        $explanation = $this->explanationService->explain(
            $subjectKey,
            $channel,
            $baseline,
            $context->findings(),
            $context->result()->thresholdOverrides,
            $this->configuredThresholds->resolve(),
            $context->result()->metrics,
        );

        if ($explanation->status === BoundaryExplanationStatus::Unknown) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                'subject',
                \sprintf(
                    'Unknown subject "%s": it is absent from both the current analysis and the baseline.',
                    $subjectKey,
                ),
            );
        }

        BaselineExplanationRenderer::render($explanation, $output);

        return self::SUCCESS;
    }

    private static function baselinePath(InputInterface $input): ?string
    {
        $path = CommandLineSpelling::option($input, 'baseline');

        return $path !== null && $path !== '' ? $path : null;
    }

    /** `null` when `--channel` was not given at all, which means "every channel". */
    private function readChannel(InputInterface $input): ?FindingChannel
    {
        $raw = CommandLineSpelling::option($input, 'channel');

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return new FindingChannel($raw);
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--channel',
                $e->getMessage(),
                $e,
            );
        }
    }

    /**
     * A channel the file mentions at all, valid entry or inert — an entry is
     * exactly {@see \Qualimetrix\Analysis\Policy\Baseline\InertEntryReason::UndeclaredChannel} when the registry
     * has already forgotten the channel a rename map has not yet carried the
     * file onto, which is precisely the legitimate case this check exists
     * for.
     */
    private static function channelInBaseline(FindingChannel $channel, ?Baseline $baseline): bool
    {
        if ($baseline === null) {
            return false;
        }

        foreach ($baseline->entries as $entry) {
            if ($entry->identity->channel->code === $channel->code) {
                return true;
            }
        }

        foreach ($baseline->inertEntries as $entry) {
            if ($entry->identity?->channel->code === $channel->code) {
                return true;
            }
        }

        return false;
    }
}
