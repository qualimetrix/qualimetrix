<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Exception;
use InvalidArgumentException;
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

        AnalysisReportCommandDefinition::addOptions($this);

        AnalysisReportCommandDefinition::addSelectionOptions($this)
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
                'Exit codes: <info>0</info> nothing inert, <info>2</info> at least one inert directive whose',
                'boundary was observable, <info>3</info> bad input or configuration, <info>4</info> the run could',
                'not parse part of the tree — which disqualifies it from calling anything',
                'dead — and <info>1</info> if the command itself failed unexpectedly.',
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
        } catch (InvalidArgumentException $failure) {
            // The options this command accepts are parsed into value objects,
            // and a malformed one is the caller's to fix — the same reading
            // `check` gives it.
            self::reportError(
                $output,
                $format,
                \sprintf('Configuration error: %s', $failure->getMessage()),
                self::EXIT_CONFIG_ERROR,
            );

            return self::EXIT_CONFIG_ERROR;
        } catch (Exception $failure) {
            // `Exception` and not `Throwable`: an `Error` is a bug in the tool,
            // and swallowing it into an exit code would hide in CI exactly the
            // failures CI exists to surface.
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

        if ($report->coverage->analyzedFilesCount() === 0 && $report->coverage->isComplete()) {
            // A run that measured nothing has no standing to call a tree clean.
            // The paths existed — they were checked above — so this is
            // `exclude`, an empty `paths:`, a directory with no PHP in it, or a
            // scope of nothing but `@generated` files, and every one of them is
            // the caller's to fix. Measured, not discovered: a discovered file
            // the run then skipped was not read either. A run that failed to
            // parse everything it found is a different answer, and the code
            // below already gives it.
            self::reportError(
                $output,
                $format,
                'Error: the configured scope analysed no PHP files, so no directive could be judged',
                self::EXIT_CONFIG_ERROR,
            );

            return self::EXIT_CONFIG_ERROR;
        }

        $exitCode = self::exitCodeFor($report);
        $selection = $prepared->findingConfiguration->selection;
        $presenter = new DirectiveAuditPresenter($report, $selection->only, $selection->disabled);

        OutputHelper::write($output, $format === 'json' ? $presenter->json($exitCode) : $presenter->text());

        return $exitCode;
    }

    /**
     * `Overrun` and `Unmeasured` are printed and move nothing: the first is a
     * promise a human has to judge, the second is the absence of an answer.
     *
     * **An `Inert` verdict moves it only where the answer was observable.**
     * Where the addressed rule published no boundary with its finding, an inert
     * directive and one whose raised boundary the value had already passed
     * produce the identical difference — the report says so, and a code that
     * demands the author delete it would be reporting an unasked question as
     * proven debt. That is what `Unmeasured` exists to prevent, and the reason
     * does not change because the shape of the ignorance does.
     */
    private static function exitCodeFor(DirectiveAuditReport $report): int
    {
        if (!$report->coverage->isComplete()) {
            return self::EXIT_INCOMPLETE_RUN;
        }

        foreach ($report->verdicts as $verdict) {
            if ($verdict->effect === DirectiveEffect::Inert && $verdict->boundaryObservable) {
                return self::EXIT_INERT_FOUND;
            }
        }

        return self::SUCCESS;
    }

    /**
     * In `json`, an error takes the envelope shape, so a parser reading stdout
     * finds JSON where a report would have been rather than an `<error>` line.
     *
     * On an interactive terminal the progress bar still writes control bytes
     * ahead of it — that is this product's behaviour for every command with a
     * machine-readable format, `check` included, and not something to fix in
     * one command. Redirect, or run where the output is not a TTY.
     */
    private static function reportError(OutputInterface $output, string $format, string $message, int $exitCode): void
    {
        if ($format === 'json') {
            OutputHelper::write($output, DirectiveAuditPresenter::jsonError($message, $exitCode));

            return;
        }

        $output->writeln(\sprintf('<error>%s</error>', $message));
    }
}
