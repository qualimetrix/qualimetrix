<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command\Debug;

use Exception;
use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\AnalysisPreflightProfile;
use Qualimetrix\Infrastructure\Console\AnalysisReportCommandDefinition;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\LayerAssignmentResolver;
use Qualimetrix\Infrastructure\Console\OutputHelper;
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
 * Exits 0 for any informational result about an analysed class (including
 * "no layer matches"), 3 for malformed input, an FQN naming no analysed
 * class, or a configuration-load error recognised as the user's to fix
 * (`ConsoleExitCode::Refusal`), and 1 (`Command::FAILURE`) for anything else
 * the configuration step throws.
 *
 * "No analysed class", "analysed, every layer answered no" and "analysed, and
 * a layer's criteria went unanswered" are three facts, so they get three
 * answers: a refusal raised by {@see LayerAssignmentResolver}, the
 * `(no layer)` report, and the `(undecided)` report. A class that exists on
 * disk but was kept out of the run by `paths`, `exclude` or the
 * generated-file filter takes the first branch — the command answers for the
 * set it analysed, not for the filesystem. The third is informational like
 * the second and exits 0: the class was analysed and the command reports what
 * the run could and could not establish, while whether an undecidable
 * membership fails the build is `architecture.coverage-gap`'s to say.
 *
 * `--format=json` renders the same {@see LayerAssignmentResolver::resolve()}
 * result as a machine-readable document instead of the human-readable
 * report; both projections read one resolution, so they cannot drift. On a
 * JSON-format error (validation failure or configuration error), the error
 * envelope replaces the report on stdout rather than the human `<error>`
 * line — an agent parsing `--format=json` output must always find valid
 * JSON there.
 *
 * @phpstan-type Resolution array{matches: list<LayerAssignmentMatch>, hasLayers: bool, undecided: list<string>, chainStopsAt: list<string>, contenders: list<string>, firstEstablished: string|null, reportedShadows: list<string>}
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
                . 'The class must be one the run analysed. An FQN that names no analysed'
                . "\n" . 'declaration — a typo, or a class kept out by <info>paths</info>, <info>exclude</info> or the'
                . "\n" . 'generated-file filter — is refused with exit code 3 rather than reported'
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
     * @throws InvalidArgumentException for an unsupported format or a malformed FQN
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
        } catch (ConfigurationRefusal $refusal) {
            return $this->refusalPresenter->refusal($output, $format, $refusal);
        } catch (InvalidArgumentException $failure) {
            return $this->refusalPresenter->fallbackRefusal($output, $format, $failure);
        }

        $symbol = SymbolPath::fromClassFqn($rawFqn);
        $normalized = $this->fqnFor($symbol);

        try {
            $resolution = $this->resolveAssignment($input, $output, $symbol);
        } catch (ConfigurationRefusal $refusal) {
            // First clause: the carrier is a RuntimeException, and the
            // `catch (Exception)` below would otherwise catch it and answer
            // with FAILURE (1) instead of the shared refusal code.
            return $this->refusalPresenter->refusal($output, $format, $refusal);
        } catch (InvalidArgumentException $e) {
            // Named secondary signal for code 3: an
            // `InvalidArgumentException` that never became a
            // carrier, caught here rather than falling through to the
            // `Exception` branch below and answering with 1.
            return $this->refusalPresenter->fallbackRefusal($output, $format, $e);
        } catch (Exception $e) {
            // Catches recoverable failures while bubbling up Errors (TypeError, etc.)
            // so genuine programming bugs in the pipeline surface in CI rather than
            // being silently reported as exit code 1. Configuration failures the
            // user can fix are refused above as `ConfigurationRefusal`; anything
            // still reaching here is not one, so it goes through the presenter's
            // `internalError()` — the same envelope and `-q`/`--silent` survival
            // every other command's internal error gets, not a local `reportError()`.
            return $this->refusalPresenter->internalError($output, $format, $e);
        }

        if ($format === 'json') {
            $this->renderJson($output, $normalized, $resolution);
        } else {
            $shadowedBy = $resolution['firstEstablished'];
            $shadowed = $shadowedBy === null ? [] : self::matchesAfter($resolution['matches'], $shadowedBy);
            (new LayerAssignmentTextPresenter($output))->render($normalized, $resolution, $shadowed);
            $output->writeln(\sprintf('<comment>%s</comment>', ProductIdentity::pointerText()));
        }

        return self::SUCCESS;
    }

    /** @return Resolution */
    private function resolveAssignment(InputInterface $input, OutputInterface $output, SymbolPath $symbol): array
    {
        $prepared = $this->preflight->resolve($input, $output, $this->preflightProfile);

        return $this->layerAssignmentResolver->resolve($prepared->runConfiguration, $symbol);
    }

    /**
     * Validates the raw FQN argument. Returns null on success, error message on failure.
     */
    private function validateFqn(string $rawFqn): ?string
    {
        if (trim($rawFqn) === '') {
            return 'Class FQN must not be empty.';
        }

        if (preg_match('/\s/', $rawFqn) === 1) {
            return \sprintf('Class FQN "%s" must not contain whitespace.', $rawFqn);
        }

        // Strip leading backslash before validating identifier characters so
        // that `\App\Foo` is treated like `App\Foo`.
        $normalized = ltrim($rawFqn, '\\');
        if ($normalized === '') {
            return 'Class FQN must contain at least one identifier segment.';
        }

        // PHP identifier segments are [A-Za-z_][A-Za-z0-9_]* joined by `\`.
        // Reject anything outside that grammar (e.g. dashes, dots, slashes).
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $normalized) !== 1) {
            return \sprintf(
                'Class FQN "%s" is not a valid PHP fully qualified class name.',
                $rawFqn,
            );
        }

        return null;
    }

    /**
     * Reconstructs the canonical FQN form (`Namespace\Type` or bare `Type`) from
     * a class-level SymbolPath. {@see SymbolPath::fromClassFqn()} normalises any
     * leading backslash and splits on the last separator, so the FQN we build
     * here is the canonical form to match against layer patterns.
     */
    private function fqnFor(SymbolPath $symbol): string
    {
        $namespace = $symbol->namespace;
        $type = $symbol->type ?? '';

        if ($namespace === null || $namespace === '') {
            return $type;
        }

        return $namespace . '\\' . $type;
    }

    /**
     * Serializes the same `resolve()` result {@see LayerAssignmentTextPresenter::render()} renders as
     * text, so both projections read one resolution and cannot drift.
     *
     * `assigned` is `null` when `$matches` is empty (no layer matched) rather
     * than an omitted key, so a consumer can branch on presence without also
     * checking `shadowed === []`.
     *
     * `shadowed` lists every match after `shadowedBy`, the first match the
     * run established: each loses the class whatever the unanswered layers
     * answer, and is flagged `reported` when `architecture.potential-shadow`
     * reports it — never for a match whose own `exclude:` went unanswered,
     * since it may not match at all. `shadowedBy` is not `assigned` when an
     * unanswered `exclude:` stands in front of it, and is `null` when
     * `shadowed` is empty.
     *
     * `contendingMatches` lists, in the same form, every other match after
     * `assigned`: the matches whose `exclude:` went unanswered and, when one
     * stands in front of it, the first match the run established. Which of
     * them owns the class depends on the unanswered clauses, so none shadows
     * or is shadowed and `reported` is always false. `contendingMatches` and
     * `shadowed` together are every match the text report lists after the
     * assignment.
     *
     * `undecided` is always present and names the layers this run could not
     * answer for the class that bear on its assignment — every one when
     * nothing assigned it, otherwise those declared before the first match
     * the run established. A null `assigned` with a non-empty `undecided` is
     * not "no layer claims this class" — it is "the run could not tell", so a
     * consumer branching on `assigned` alone must read this key too.
     * `contenders` names the layers that could own the class once those are
     * answered, and is empty whenever `undecided` is.
     *
     * `chainStopsAt` is always present and names where the class's
     * inheritance chain stopped at a declaration the run did not read; it is
     * empty whenever `undecided` is.
     *
     * @param Resolution $resolution
     */
    private function renderJson(OutputInterface $output, string $fqn, array $resolution): void
    {
        $assigned = $resolution['matches'][0] ?? null;
        $reported = $resolution['reportedShadows'];
        $shadowedBy = $resolution['firstEstablished'];
        $shadowed = $shadowedBy === null ? [] : self::matchesAfter($resolution['matches'], $shadowedBy);
        $contending = \array_slice($resolution['matches'], 1, max(0, \count($resolution['matches']) - 1 - \count($shadowed)));
        $toEntry = static fn(LayerAssignmentMatch $match): array => self::matchToArray($match)
            + ['reported' => \in_array($match->layerName, $reported, true)];

        OutputHelper::write($output, $this->encodeJson([
            'meta' => ProductIdentity::meta(gmdate('c')),
            'fqn' => $fqn,
            'assigned' => $assigned === null ? null : self::matchToArray($assigned),
            'contendingMatches' => array_map($toEntry, $contending),
            'shadowed' => array_map($toEntry, $shadowed),
            'shadowedBy' => $shadowed === [] ? null : $shadowedBy,
            'undecided' => $resolution['undecided'],
            'contenders' => $resolution['contenders'],
            'chainStopsAt' => $resolution['chainStopsAt'],
            'hasLayers' => $resolution['hasLayers'],
        ]));
    }

    /**
     * @param list<LayerAssignmentMatch> $matches
     *
     * @return list<LayerAssignmentMatch> the matches declared after `$layerName`
     */
    private static function matchesAfter(array $matches, string $layerName): array
    {
        foreach ($matches as $position => $match) {
            if ($match->layerName === $layerName) {
                return \array_slice($matches, $position + 1);
            }
        }

        return [];
    }

    /** @return array{layer: string, criteria: non-empty-list<string>} */
    private static function matchToArray(LayerAssignmentMatch $match): array
    {
        return [
            'layer' => $match->layerName,
            'criteria' => $match->criteria,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function encodeJson(array $payload): string
    {
        return json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }
}
