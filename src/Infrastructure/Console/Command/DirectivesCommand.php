<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\AnalysisReportCommandDefinition;
use Qualimetrix\Infrastructure\Console\ConfigurationFailure;
use Qualimetrix\Infrastructure\Console\DirectiveAuditPresenter;
use Qualimetrix\Infrastructure\Console\OutputHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * What every `@qmx-ignore` and `@qmx-threshold` in the analysed tree did.
 *
 * Top-level rather than `debug:`, by the criterion in `CLI_CONVENTIONS.md`: it
 * takes source code and produces analysis output. It is not diagnostics of the
 * tool's internals — it is maintenance of the project's own annotations, and
 * the answer it gives is meant for CI.
 *
 * **A verdict is relative to the run that produced it**, and the report says
 * which run that was. A threshold retuning a metric computed over the analysed
 * subgraph — coupling is the standing case — is live over the whole tree and
 * dead over a subdirectory of it, and neither answer is wrong. So the scope
 * belongs to the caller: point the command at what the project actually
 * analyses, not at a slice of it.
 *
 * The universe judged against is everything the rules **produced**, not what a
 * report would have published. `exclude_paths`, `exclude_namespaces` and
 * `exclude_namespace_channels` filter publication; a directive that moves a
 * finding inside an excluded namespace still did something, and calling it dead
 * because a report would not have printed it is the mistake this command exists
 * to avoid making.
 */
#[AsCommand(
    name: 'directives',
    description: 'Report what each inline @qmx directive in the analysed tree actually does',
)]
final class DirectivesCommand extends Command
{
    private const array SUPPORTED_FORMATS = ['text', 'json'];

    /** A run that failed to parse part of the tree is not entitled to call anything dead. */
    private const int EXIT_INCOMPLETE_RUN = 4;

    /** `1` means "warnings" in this product, and a verdict has no second degree of severity. */
    private const int EXIT_INERT_FOUND = 2;

    private const int EXIT_CONFIG_ERROR = 3;

    public function __construct(
        private readonly DirectiveAuditInterface $directiveAudit,
        private readonly AnalysisPreflight $preflight,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'paths',
            InputArgument::IS_ARRAY,
            'Paths to analyse (defaults to the paths configured in qmx.yaml)',
        );

        AnalysisReportCommandDefinition::addOptions($this)
            ->setHelp(implode("\n", [
                'Answers, for every inline directive in the analysed tree, whether it still',
                'does anything. A `@qmx-ignore` is judged by what it silenced; a',
                '<info>@qmx-threshold</info> is judged by removing it and executing the rules again over',
                "the run's own measurements, which costs one execution per directive.",
                '',
                'A verdict is relative to the analysed scope, which the report prints. A',
                'threshold on a metric computed over the analysed subgraph — coupling above',
                'all — can be alive over the whole project and dead over one directory of',
                'it. Analyse what the project analyses.',
                '',
                'The question is asked against every finding the rules produced, including',
                'those a report would have dropped through <info>exclude_paths</info>,',
                '<info>exclude_namespaces</info> or <info>exclude_namespace_channels</info>: those suppress',
                'publication, not measurement, and a directive that moved such a finding',
                'did something.',
                '',
                'Exit codes: <info>0</info> nothing inert, <info>2</info> at least one inert directive,',
                '<info>3</info> bad input or configuration, <info>4</info> the run could not parse part of the',
                'tree — which disqualifies it from calling anything dead.',
                '',
                'Examples:',
                '  <info>bin/qmx directives src/</info>',
                '  <info>bin/qmx directives src/ --format=json</info>',
            ]));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $format */
        $format = $input->getOption('format');
        if (!\in_array($format, self::SUPPORTED_FORMATS, true)) {
            // Reported as text: an unrecognised `--format` is not a request for
            // any particular format, so there is none to honour here.
            $output->writeln(\sprintf(
                '<error>Unknown format "%s". Supported formats: %s.</error>',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));

            return self::EXIT_CONFIG_ERROR;
        }

        try {
            return $this->audit($input, $output, $format);
        } catch (Throwable $failure) {
            $configuration = ConfigurationFailure::message($failure);
            if ($configuration !== null) {
                self::reportError($output, $format, $configuration, self::EXIT_CONFIG_ERROR);

                return self::EXIT_CONFIG_ERROR;
            }

            self::reportError(
                $output,
                $format,
                \sprintf('Unexpected error: %s', $failure->getMessage()),
                self::FAILURE,
            );

            return self::FAILURE;
        }
    }

    private function audit(InputInterface $input, OutputInterface $output, string $format): int
    {
        $prepared = $this->preflight->resolve($input, $output);

        $missing = AnalysisPreflight::missingPaths($prepared->runConfiguration);
        if ($missing !== []) {
            // Every one of them, as `check` reports them: a user who mistyped
            // two paths should learn both from one run.
            self::reportError($output, $format, implode("\n", $missing), self::EXIT_CONFIG_ERROR);

            return self::EXIT_CONFIG_ERROR;
        }

        // The discovery the preflight resolved, not the pipeline's default: the
        // default knows nothing of the user's `exclude`, and a verdict is
        // relative to the file set that was measured.
        $report = $this->directiveAudit->auditDirectives(
            $prepared->runConfiguration,
            $prepared->fileDiscovery,
        );

        $exitCode = self::exitCodeFor($report);
        $selection = $prepared->findingConfiguration->selection;
        $presenter = new DirectiveAuditPresenter($report, $selection->only, $selection->disabled);

        OutputHelper::write($output, $format === 'json' ? $presenter->json($exitCode) : $presenter->text());

        return $exitCode;
    }

    /**
     * `Overrun` and `Unmeasured` are printed and move nothing: the first is a
     * promise a human has to judge, the second is the absence of an answer.
     */
    private static function exitCodeFor(DirectiveAuditReport $report): int
    {
        if (!$report->coverage->isComplete()) {
            return self::EXIT_INCOMPLETE_RUN;
        }

        foreach ($report->verdicts as $verdict) {
            if ($verdict->effect === DirectiveEffect::Inert) {
                return self::EXIT_INERT_FOUND;
            }
        }

        return self::SUCCESS;
    }

    /** In `json`, an error takes the envelope shape, so a parser always finds JSON on stdout. */
    private static function reportError(OutputInterface $output, string $format, string $message, int $exitCode): void
    {
        if ($format === 'json') {
            OutputHelper::write($output, DirectiveAuditPresenter::jsonError($message, $exitCode));

            return;
        }

        $output->writeln(\sprintf('<error>%s</error>', $message));
    }
}
