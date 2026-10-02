<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Exception;
use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\AnalysisReportCommandDefinition;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\DirectiveAuditPresenter;
use Qualimetrix\Infrastructure\Console\OutputHelper;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
 * report would have published. `suppress_paths`, `suppress_namespaces` and
 * `suppress_namespace_channels` filter publication; a directive that moves a
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

    public function __construct(
        private readonly DirectiveAuditInterface $directiveAudit,
        private readonly AnalysisPreflight $preflight,
        private readonly RefusalPresenter $refusalPresenter,
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

        $this->addOption(
            'sweep',
            null,
            InputOption::VALUE_REQUIRED,
            'How much of the rule layer each counterfactual runs: narrow (default) or full',
            DirectiveSweepScope::Narrow->value,
        );

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
                'those a report would have dropped through <info>suppress_paths</info>,',
                '<info>suppress_namespaces</info> or <info>suppress_namespace_channels</info>: those suppress',
                'publication, not measurement, and a directive that moved such a finding',
                'did something.',
                '',
                'A <info>@qmx-threshold</info> names one rule, so by default only that rule is',
                "re-executed — <info>--sweep=narrow</info>. <info>--sweep=full</info> re-executes every enabled rule",
                'for the same answer at many times the cost; it exists so the two can be',
                'compared on a real tree: that removing a directive of one rule cannot move',
                "another rule's findings is a claim this project measures rather than assumes,",
                'and a difference between the two scopes is a defect, not a preference.',
                '',
                'Exit codes: <info>0</info> nothing inert, <info>2</info> at least one inert directive whose',
                'boundary was observable, <info>3</info> bad input or configuration, <info>4</info> the run could',
                'not parse part of the tree — which disqualifies it from calling anything',
                'dead — and <info>1</info> if the command itself failed unexpectedly.',
                '',
                'Examples:',
                '  <info>bin/qmx directives src/</info>',
                '  <info>bin/qmx directives src/ --format=json</info>',
                '',
                \sprintf('Docs: %s', ProductIdentity::llmsTxtUrl()),
            ]));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The envelope's format is the one written, read before anything can
        // refuse; a value of another type is refused inside the ladder below.
        $rawFormat = $input->getOption('format');
        $format = \is_string($rawFormat) ? $rawFormat : null;

        try {
            $format = CommandLineSpelling::option($input, 'format') ?? '';
            if (!\in_array($format, self::SUPPORTED_FORMATS, true)) {
                // A refusal like any other in this command's `--sweep`,
                // `paths` and empty-scope checks below: the carrier lets one
                // `catch` clause own presentation instead of every check
                // duplicating the JSON/text branch inline.
                throw ConfigurationRefusal::aboutCommandLineInput(
                    '--format',
                    \sprintf(
                        'Unknown format "%s". Supported formats: %s.',
                        $format,
                        implode(', ', self::SUPPORTED_FORMATS),
                    ),
                );
            }

            return $this->audit($input, $output, $format);
        } catch (ConfigurationRefusal $refusal) {
            // First clause: the carrier is a RuntimeException, and the
            // `catch (Exception)` clause below would otherwise catch it and
            // answer with the wrong text and, on a product defect, the wrong
            // code. `$format` here is always one of `self::SUPPORTED_FORMATS`
            // or the raw (possibly invalid) value read above — the presenter
            // decides the stream from {@see MachineReadableFormats}, not from
            // whether this command itself supports the value, so an unknown
            // `--format` still lands on stdout as a JSON envelope when it
            // names one of the five JSON-document formats (e.g.
            // `--format=sarif`, which this command does not support) and
            // falls through to stderr for the other seven.
            return $this->refusalPresenter->refusal($output, $format, $refusal);
        } catch (InvalidArgumentException $failure) {
            // Named secondary signal for code 3: an
            // `InvalidArgumentException` that never became a
            // carrier. Read as the caller's mistake, exactly as `check` reads
            // it — the path and symbol value objects throw the same class on
            // a violated invariant, and one of those is a bug in the tool
            // wearing a configuration error's clothes. Diverging from `check`
            // here would be worse — one malformed option, two exit codes,
            // depending on which command saw it — so the coarseness is
            // inherited deliberately.
            return $this->refusalPresenter->fallbackRefusal($output, $format, $failure);
        } catch (Exception $failure) {
            // `Exception` and not `Throwable`: an `Error` is a bug in the tool,
            // and swallowing it into an exit code would hide in CI exactly the
            // failures CI exists to surface. Routed through the shared
            // presenter's `internalError()` — the same envelope and
            // `-q`/`--silent` survival every other command's internal error
            // gets, not a local `reportError()`.
            return $this->refusalPresenter->internalError($output, $format, $failure);
        }
    }

    private function audit(InputInterface $input, OutputInterface $output, string $format): int
    {
        $requestedSweep = CommandLineSpelling::option($input, 'sweep') ?? '';
        $sweep = DirectiveSweepScope::tryFrom($requestedSweep);

        if ($sweep === null) {
            // Refused before the run rather than defaulted through: an
            // unrecognised value is a caller who asked for a measurement this
            // command cannot make, and answering with the other one would put
            // a scope in the report's header that nobody requested.
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--sweep',
                \sprintf(
                    'Unknown sweep "%s". Supported scopes: %s.',
                    $requestedSweep,
                    implode(', ', array_map(
                        static fn(DirectiveSweepScope $scope): string => $scope->value,
                        DirectiveSweepScope::cases(),
                    )),
                ),
            );
        }

        $prepared = $this->preflight->resolve($input, $output);

        $report = $this->directiveAudit->auditDirectives(
            $prepared->runConfiguration,
            $sweep,
        );

        if ($report->coverage->analyzedFilesCount() === 0 && $report->coverage->isComplete() && !$report->coverage->isIntentionallyEmpty()) {
            // A complete but truly empty selection has no standing to call a
            // tree clean. Named exclusions and generated-only runs are measured
            // intentional empties; failed files take the incomplete exit below.
            throw ConfigurationRefusal::aboutResolvedInput(
                'the configured scope analysed no PHP files, so no directive could be judged',
            );
        }

        $selection = ($prepared->findingConfiguration
            ?? throw new LogicException('Directive auditing requires a finding configuration.'))->enablement
            ?? throw new LogicException('Directive auditing requires final rule enablement.');
        $exitCode = self::exitCodeFor($report, $selection);
        $presenter = new DirectiveAuditPresenter($report, $selection);

        if ($format === 'json') {
            OutputHelper::write($output, $presenter->json($exitCode));
        } else {
            OutputHelper::write($output, $presenter->text());
            $output->writeln(\sprintf('<comment>%s</comment>', ProductIdentity::pointerText()));
        }

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
    private static function exitCodeFor(DirectiveAuditReport $report, RuleEnablement $enablement): int
    {
        if (!$report->coverage->isComplete()) {
            return self::EXIT_INCOMPLETE_RUN;
        }

        foreach ($report->verdicts as $verdict) {
            foreach ($verdict->refusals as $refusal) {
                if ($enablement->publishes($refusal->channel, SymbolLevel::File, $refusal->addressedProducer)) {
                    return self::EXIT_INERT_FOUND;
                }
            }
            if ($verdict->effect === DirectiveEffect::Inert && $verdict->boundaryObservable) {
                return self::EXIT_INERT_FOUND;
            }
        }

        return self::SUCCESS;
    }
}
