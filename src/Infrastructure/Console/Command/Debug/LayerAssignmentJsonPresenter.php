<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command\Debug;

use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignment;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentShadowVerdict;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\OutputHelper;
use Symfony\Component\Console\Output\OutputInterface;

/** Machine-readable projection of one resolved layer assignment. */
final readonly class LayerAssignmentJsonPresenter
{
    public function __construct(private OutputInterface $output) {}

    public function render(LayerAssignment $assignment): void
    {
        $shadowed = array_map(
            fn(LayerAssignmentShadowVerdict $verdict): array => $this->shadowVerdict($verdict, $assignment),
            $assignment->shadowVerdicts,
        );

        OutputHelper::write($this->output, self::encode([
            'meta' => ProductIdentity::meta(gmdate('c')),
            'fqn' => $assignment->declaredSpelling,
            'assigned' => isset($assignment->matches[0]) ? self::match($assignment->matches[0]) : null,
            'contendingMatches' => $this->contendingMatches($assignment),
            'shadowed' => $shadowed,
            'shadowedBy' => $shadowed === [] ? null : $assignment->firstEstablished,
            'undecided' => $assignment->undecidedLayers,
            'contenders' => $assignment->contenders,
            'chainStopsAt' => $assignment->chainStopsAt,
            'hasLayers' => $assignment->hasLayers,
            'policyDisabled' => $assignment->policyDisabled,
            'edgeEndOnly' => $assignment->edgeEndOnly,
        ]));
    }

    /** @return list<array{layer: string, criteria: non-empty-list<string>, reported?: bool}> */
    private function contendingMatches(LayerAssignment $assignment): array
    {
        $shadowed = array_fill_keys(array_map(
            static fn(LayerAssignmentShadowVerdict $verdict): string => $verdict->match->layerName,
            $assignment->shadowVerdicts,
        ), true);
        $matches = array_filter(
            \array_slice($assignment->matches, 1),
            static fn(LayerAssignmentMatch $match): bool => !isset($shadowed[$match->layerName]),
        );

        return array_values(array_map(
            static fn(LayerAssignmentMatch $match): array => self::match($match)
                + ($assignment->policyDisabled ? [] : ['reported' => false]),
            $matches,
        ));
    }

    /** @return array{layer: string, criteria: non-empty-list<string>} */
    private static function match(LayerAssignmentMatch $match): array
    {
        return ['layer' => $match->layerName, 'criteria' => $match->criteria];
    }

    /** @return array{layer: string, criteria: non-empty-list<string>, reported?: bool, exemption?: string|null} */
    private function shadowVerdict(
        LayerAssignmentShadowVerdict $verdict,
        LayerAssignment $assignment,
    ): array {
        if ($assignment->policyDisabled) {
            return self::match($verdict->match);
        }

        return self::match($verdict->match)
            + ['reported' => $verdict->reported()]
            + ($verdict->exemption === null ? [] : ['exemption' => $verdict->exemption->value]);
    }

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload): string
    {
        return json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }
}
