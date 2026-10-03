<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command\Debug;

use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Human-readable layer assignment and the limits of its collected evidence.
 *
 * @phpstan-type Resolution array{matches: list<LayerAssignmentMatch>, hasLayers: bool, undecided: list<string>, chainStopsAt: list<string>, contenders: list<string>, firstEstablished: string|null, reportedShadows: list<string>}
 */
final readonly class LayerAssignmentTextPresenter
{
    public function __construct(private OutputInterface $output) {}

    /**
     * @param Resolution $resolution
     * @param list<LayerAssignmentMatch> $shadowed
     */
    public function render(string $fqn, array $resolution, array $shadowed): void
    {
        $matches = $resolution['matches'];
        $undecided = $resolution['undecided'];

        $this->output->writeln(\sprintf('Class: <info>%s</info>', $fqn));
        $this->output->writeln('');

        if ($matches === [] && $undecided !== []) {
            $this->renderUndecided($undecided, $resolution['chainStopsAt']);

            return;
        }

        if ($matches === []) {
            $this->renderNoLayer($resolution);

            return;
        }

        $assigned = $matches[0];
        $this->output->writeln(\sprintf('  Assigned to: <info>%s</info>', $assigned->layerName));
        $this->output->writeln(\sprintf('    Matched by: <comment>%s</comment>', self::describeCriteria($assigned)));
        $this->renderAssignmentUncertainty($resolution);
        $this->output->writeln('');

        $this->renderAdditionalMatches($resolution, $shadowed);
    }

    /** @param Resolution $resolution */
    private function renderNoLayer(array $resolution): void
    {
        $this->output->writeln('  Assigned to: <comment>(no layer)</comment>');
        $this->output->writeln('');
        if (!$resolution['hasLayers']) {
            $this->output->writeln('  Suggestion: no layers are declared in the configuration. Add an');
            $this->output->writeln('  <comment>architecture.layers</comment> section to qmx.yaml to start enforcing');
            $this->output->writeln('  layer boundaries.');
        } else {
            $this->output->writeln('  Suggestion: declare a catch-all layer with pattern <comment>\'**\'</comment> at the');
            $this->output->writeln('  end of the layers list to capture unclassified classes.');
        }
    }

    /** @param Resolution $resolution */
    private function renderAssignmentUncertainty(array $resolution): void
    {
        $undecided = $resolution['undecided'];
        if ($undecided !== []) {
            // The assignment is not withdrawn by an unanswered layer — see
            // `LayerRegistry::undecidedLayers()` for why — but printing it
            // alone would hide that the layers named here might change it.
            // The list already holds only the layers bearing on the
            // assignment (those declared before the first match the run
            // established), so "it can change" is true of every one of them.
            $this->output->writeln(\sprintf('    Could not be decided: <comment>%s</comment>', implode(', ', $undecided)));
            $this->output->writeln(\sprintf('    Could be owned by: <comment>%s</comment>', implode(', ', $resolution['contenders'])));
            $this->output->writeln(\sprintf('    The chain stops at: <comment>%s</comment>', implode(', ', $resolution['chainStopsAt'])));
            $this->output->writeln('    The assignment above is what the answered layers give; it can change');
            $this->output->writeln('    once every link of this class\'s inheritance chain is analysed.');
        }
    }

    /**
     * @param Resolution $resolution
     * @param list<LayerAssignmentMatch> $shadowed
     */
    private function renderAdditionalMatches(array $resolution, array $shadowed): void
    {
        $alsoMatching = \array_slice($resolution['matches'], 1);

        $this->output->writeln('  Would also match (in declaration order):');
        if ($alsoMatching === []) {
            $this->output->writeln('    <comment>(none — the assignment is unique)</comment>');

            return;
        }

        $maxLayerNameWidth = max(array_map(
            static fn(LayerAssignmentMatch $entry): int => \strlen($entry->layerName),
            $alsoMatching,
        ));

        foreach ($alsoMatching as $entry) {
            $this->output->writeln(\sprintf(
                "    - %-{$maxLayerNameWidth}s (matched by: '<comment>%s</comment>')",
                $entry->layerName,
                self::describeCriteria($entry),
            ));
        }

        $this->renderShadowHint($resolution, $shadowed);
    }

    /**
     * The hint follows the rule `architecture.potential-shadow` draws its
     * pairs by, so the command never sends the reader to a diagnostic that
     * says nothing about this class: a match whose `exclude:` went unanswered
     * neither shadows nor is shadowed, and a layer broader than the one it
     * loses to is the narrow-before-broad idiom rather than a defect.
     *
     * @param Resolution $resolution
     * @param list<LayerAssignmentMatch> $shadowed
     */
    private function renderShadowHint(array $resolution, array $shadowed): void
    {
        $shadowedBy = $resolution['firstEstablished'];
        if ($shadowedBy === null || $shadowed === []) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('  Diagnostic hint:');
        $reported = $resolution['reportedShadows'];
        if ($reported === []) {
            $this->output->writeln(\sprintf(
                "    The later matches lose the class to '<info>%s</info>', and no diagnostic reports",
                $shadowedBy,
            ));
            $this->output->writeln('    them as a shadow: a broader layer after a narrower one is how declaration');
            $this->output->writeln('    order is meant to be used, and a match whose exclude: went unanswered may');
            $this->output->writeln('    not match at all.');

            return;
        }

        $this->output->writeln(\sprintf(
            "    Class is shadowed: would have matched '<info>%s</info>' if '<info>%s</info>' was declared later.",
            $reported[0],
            $shadowedBy,
        ));
        $this->output->writeln('    See <comment>architecture.potential-shadow</comment> diagnostic for the broader picture.');
    }

    /**
     * The report for a class no layer claims *and* no layer answered about.
     *
     * Kept apart from the `(no layer)` branch because the two differ in what
     * the reader should do next. An unclassified class is closed by writing a
     * layer. This one is closed for certain only by analysing where its chain
     * stops; a layer declared after the unanswered one would also assign it,
     * but as a guess, so that edit is named with its cost rather than offered
     * as the cure. The wording follows `architecture.coverage-gap`, which
     * counts the same two populations separately from the same walk, so the
     * two readers of one fact do not describe it differently.
     *
     * @param list<string> $undecided
     * @param list<string> $chainStopsAt
     */
    private function renderUndecided(array $undecided, array $chainStopsAt): void
    {
        $this->output->writeln('  Assigned to: <comment>(undecided)</comment>');
        $this->output->writeln(\sprintf('    Could not be decided: <comment>%s</comment>', implode(', ', $undecided)));
        $this->output->writeln(\sprintf('    The chain stops at: <comment>%s</comment>', implode(', ', $chainStopsAt)));
        $this->output->writeln('');
        $this->output->writeln('  A declared <comment>extends</comment>/<comment>implements</comment>/<comment>attributes</comment> criterion reads facts this');
        $this->output->writeln('  run did not collect: where the chain stops is outside the analysed paths.');
        $this->output->writeln('  No layer matched, and a layer could not answer.');
        $this->output->writeln('');
        $this->output->writeln('  Suggestion: widen <comment>paths</comment> to include those declarations — for your own code');
        $this->output->writeln('  that decides the layer; for vendor code it means analysing that package. A');
        $this->output->writeln('  layer declared after the unanswered one, a catch-all included, would assign');
        $this->output->writeln('  this class, but as a guess: it may belong to the layer that could not answer.');
        $this->output->writeln('  <comment>architecture.coverage-gap</comment> counts these separately from classes every');
        $this->output->writeln('  criterion answered "no" about.');
    }

    private static function describeCriteria(LayerAssignmentMatch $entry): string
    {
        return implode(', ', $entry->criteria);
    }
}
