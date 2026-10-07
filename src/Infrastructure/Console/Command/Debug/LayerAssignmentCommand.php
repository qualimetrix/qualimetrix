<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command\Debug;

use Exception;
use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignment;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\AnalysisPreflightProfile;
use Qualimetrix\Infrastructure\Console\AnalysisReportCommandDefinition;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\LayerAssignmentResolver;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Per-class introspection of layer assignment.
 *
 * Reports which layer the supplied class would be assigned to under the
 * project's architecture configuration, and which other layers' patterns
 * would have matched the class as well. Useful for understanding silent
 * shadowing — when a class falls into one layer because an earlier layer's
 * pattern happened to match it, even though a later, more specific layer
 * looks like a better fit.
 *
 * The command runs the full Discovery + Collection phases so the per-class
 * answer matches {@code qmx check} byte-for-byte under both template-layer
 * and graph-criteria configurations (ADR 0008). Resolution itself is
 * performed by the shared {@see LayerAssignmentResolver} so the
 * matching algorithm has a single source of truth.
 *
 * Exits 0 for any informational result about an observed class name (including
 * "no layer matches"), 3 for empty input, an FQN absent from declarations and
 * graph ends, or a configuration-load error recognised as the user's to fix
 * (`ConsoleExitCode::Refusal`), and 1 (`Command::FAILURE`) for anything else
 * the configuration step throws.
 *
 * "No observed class", "observed, every layer answered no" and "observed, and
 * a layer's criteria went unanswered" are three facts, so they get three
 * answers: a refusal raised by {@see LayerAssignmentResolver}, the
 * `(no layer)` report, and the `(undecided)` report. A class that exists on
 * disk but was kept out of the run by `paths`, `exclude` or the
 * generated-file filter takes the first branch unless a graph end still names
 * it. The third is informational like the second and exits 0: the command
 * reports what the run could and could not establish, while whether an undecidable
 * membership fails the build is `architecture.coverage-gap`'s to say.
 *
 * `--format=json` renders the same {@see LayerAssignmentResolver::resolve()}
 * result as a machine-readable document instead of the human-readable
 * report; both projections read one resolution, so they cannot drift. On a
 * JSON-format error (validation failure or configuration error), the error
 * envelope replaces the report on stdout rather than the human `<error>`
 * line — an agent parsing `--format=json` output must always find valid
 * JSON there.
 */
#[AsCommand(
    name: 'debug:layer-assignment',
    description: 'Show which architecture layer a class would be assigned to',
)]
final class LayerAssignmentCommand extends Command
{
    private const array SUPPORTED_FORMATS = ['text', 'json'];

    public function __construct(
        private readonly AnalysisPreflight $preflight,
        private readonly AnalysisPreflightProfile $preflightProfile,
        private readonly LayerAssignmentResolver $layerAssignmentResolver,
        private readonly RefusalPresenter $refusalPresenter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'fqn',
            InputArgument::REQUIRED,
            'Fully qualified class name to inspect (e.g. App\\Service\\Foo)',
        );

        AnalysisReportCommandDefinition::addOptions($this)
            ->setHelp(
                'Reports the layer the given class is assigned to under the project'
                . "\n" . 'architecture configuration, plus every other layer whose criteria'
                . "\n" . 'would have matched the class (would have been the assignment if'
                . "\n" . 'declared earlier).' . "\n\n"
                . 'Layer evaluation follows declaration order: the first layer whose'
                . "\n" . 'criteria match wins. Reorder layers in qmx.yaml or tighten broad'
                . "\n" . 'patterns to resolve unwanted shadowing.' . "\n\n"
                . 'The class must be observed in a declaration or at a dependency-graph end.'
                . "\n" . 'A name absent from that collected state is refused with exit code 3 rather than reported'
                . "\n" . 'as unclassified.' . "\n\n"
                . 'The command runs full Discovery + Collection internally so the answer'
                . "\n" . 'matches `qmx check` byte-for-byte for template-layer and'
                . "\n" . 'graph-based configurations. Expect roughly 50–70% of `qmx check`'
                . "\n" . 'runtime.' . "\n\n"
                . '<info>--format=json</info> renders the same resolution as a machine-readable'
                . "\n" . 'document instead of the text report; use it to avoid parsing'
                . "\n" . 'human-readable formatting.' . "\n\n"
                . 'Examples:' . "\n"
                . '  <info>bin/qmx debug:layer-assignment \'App\\Service\\Foo\'</info>' . "\n"
                . '  <info>bin/qmx debug:layer-assignment \'App\\Service\\Foo\' --config qmx.yaml</info>' . "\n"
                . '  <info>bin/qmx debug:layer-assignment \'App\\Service\\Foo\' --format=json</info>' . "\n\n"
                . \sprintf('Docs: %s', ProductIdentity::llmsTxtUrl()),
            );
    }

    /**
     * The supported format and the FQN, as written.
     *
     *
     * @throws ConfigurationRefusal for a value no command line can spell
     * @throws InvalidArgumentException for an unsupported format or an empty FQN
     *
     * @return array{string, string}
     */
    private function request(InputInterface $input): array
    {
        $format = CommandLineSpelling::option($input, 'format') ?? '';
        if (!\in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new InvalidArgumentException(\sprintf(
                'Unknown format "%s". Supported formats: %s.',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));
        }

        $rawFqn = CommandLineSpelling::requiredArgument($input, 'fqn');
        $validationError = $this->validateFqn($rawFqn);
        if ($validationError !== null) {
            throw new InvalidArgumentException($validationError);
        }

        return [$format, $rawFqn];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawFormat = $input->getOption('format');
        $format = \is_string($rawFormat) ? $rawFormat : null;

        // Malformed CLI input is a refusal (exit 3), not `Command::INVALID`.
        // Routed through the shared presenter (not a local `writeln()`) so
        // the framing, the stream and the `-q`/`--silent` survival contract
        // are the same one every other command's refusal gets.
        try {
            [$format, $rawFqn] = $this->request($input);
        } catch (RefusalInterface $refusal) {
            return $this->refusalPresenter->refusal($output, $format, $refusal);
        } catch (InvalidArgumentException $failure) {
            return $this->refusalPresenter->fallbackRefusal($output, $format, $failure);
        }

        $symbol = SymbolPath::fromClassFqn($rawFqn);
        try {
            $assignment = $this->resolveAssignment($input, $output, $symbol);
        } catch (RefusalInterface $refusal) {
            return $this->refusalPresenter->refusal($output, $format, $refusal);
        } catch (InvalidArgumentException $e) {
            // Named secondary signal for code 3: an
            // `InvalidArgumentException` that never became a
            // carrier, caught here rather than falling through to the
            // `Exception` branch below and answering with 1.
            return $this->refusalPresenter->fallbackRefusal($output, $format, $e);
        } catch (Exception $e) {
            // Core failures can arrive from collection or inspection without
            // an intermediate Console translation.
            return $this->refusalPresenter->unhandled($output, $format, $e);
        }

        $declaredSpelling = $assignment->declaredSpelling
            ?? throw new LogicException('A resolved layer assignment requires an observed declaration spelling.');
        if ($format === 'json') {
            (new LayerAssignmentJsonPresenter($output))->render($assignment);
        } else {
            (new LayerAssignmentTextPresenter($output))->render($declaredSpelling, $assignment);
        }

        return self::SUCCESS;
    }

    private function resolveAssignment(InputInterface $input, OutputInterface $output, SymbolPath $symbol): LayerAssignment
    {
        $prepared = $this->preflight->resolve($input, $output, $this->preflightProfile);

        return $this->layerAssignmentResolver->resolve(
            $prepared->runConfiguration,
            $symbol,
            self::policyDisabled($prepared->findingConfiguration),
        );
    }

    /** Rejects only an empty spelling; collected identities judge every other input. */
    private function validateFqn(string $rawFqn): ?string
    {
        if (trim($rawFqn) === '') {
            return 'Class FQN must not be empty.';
        }

        $normalized = ltrim($rawFqn, '\\');
        if ($normalized === '') {
            return 'Class FQN must contain at least one identifier segment.';
        }

        return null;
    }

    private static function policyDisabled(?FindingConfiguration $configuration): bool
    {
        if ($configuration === null) {
            throw new LogicException('debug:layer-assignment requires analysis preflight with completed finding enablement.');
        }
        $enablement = $configuration->enablement
            ?? throw new LogicException('debug:layer-assignment requires analysis preflight with completed finding enablement.');
        foreach (ArchitectureChannels::PRODUCERS as $producer) {
            if ($enablement->runs($producer)) {
                return false;
            }
        }

        return true;
    }

}
