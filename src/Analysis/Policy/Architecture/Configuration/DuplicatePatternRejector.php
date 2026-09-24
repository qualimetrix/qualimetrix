<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;

/**
 * Rejects duplicate {@code patterns} declared across different
 * {@code architecture.layers[*]} entries. Under declaration-order semantics
 * any class matching the duplicate belongs to the earlier entry whenever that
 * entry takes its whole pattern — the second occurrence is then unreachable
 * and a configuration mistake.
 *
 * Same-pattern entries within ONE entry are not duplicates (the entry
 * itself can list whatever it wants), so the check is cross-entry only.
 *
 * Only the {@code patterns} criterion is duplicate-checked: suffix /
 * attributes / implements / extends entries can legitimately overlap
 * across entries because their match semantics are richer than a literal
 * FQN prefix (a suffix-only class might match multiple suffix entries; the
 * declaration-order rule already chooses the assignment unambiguously).
 *
 * Both {@see LayerDefinition} and {@see TemplateLayerDefinition} are
 * walked uniformly — a static entry's pattern colliding with a template's
 * raw pattern would also be unreachable under declaration order.
 *
 * **Only an entry that takes every class its pattern names makes a later
 * occurrence unreachable** — {@see MembershipSpec::ownsItsPatterns()}, the
 * same predicate `architecture.potential-shadow` asks
 * ({@see \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing}),
 * so a repeated pattern this check accepts is not then reported as a shadow on
 * every run. Two kinds of entry take less, and neither owns the pattern:
 *
 * - One declaring {@code match: all} together with a non-empty non-pattern
 *   criterion (suffix / attributes / implements / extends) claims only the
 *   pattern matches that also satisfy the extra criteria, leaving the residue
 *   to a sibling (H1 remediation).
 * - One declaring an {@code exclude:} clause hands every class the clause
 *   removes to the next layer that matches it. Repeating the pattern on a
 *   later layer is how a carve-out is written, and refusing it made the
 *   simplest carve-out unloadable while the refusal called it unreachable.
 *   Whether the later layer reaches anything is a question about the code:
 *   `architecture.unreachable-layer` answers it at run time, which is the
 *   trace a clause that removes nothing still leaves.
 *
 * A later entry is refused against the first entry that does own the pattern,
 * so a third occurrence behind a carve-out's recipient is refused naming that
 * recipient.
 *
 * Trade-off: a {@code match: all} narrowing entry sitting AFTER an owning
 * entry on the same pattern is technically unreachable, and is still accepted
 * — losing a "rare unreachable layer" warning is less harmful than rejecting
 * a valid config, and `architecture.unreachable-layer` reports it at run
 * time. A later entry's own {@code exclude:} gets no such pass: it narrows
 * what that entry takes, not what the earlier one already took.
 *
 * Split out of {@see LayersValidator} (whose entry-shape validation is a
 * different concern from this cross-entry reachability check) to keep each
 * class's cyclomatic weight readable; both stay in the same namespace so
 * the schema surface remains co-located.
 */
final class DuplicatePatternRejector
{
    /**
     * @param list<LayerDefinition|TemplateLayerDefinition> $entries
     */
    public static function reject(array $entries): void
    {
        $owners = [];
        foreach ($entries as $entryIndex => $entry) {
            self::rejectEntryPatterns($entry, $entryIndex, $owners);
        }
    }

    /**
     * Walks one entry's own patterns, deduplicated within the entry, against
     * the cross-entry `$owners` map built so far.
     *
     * @param array<string, array{name: string, index: int}> $owners pattern => the first entry that owns it
     *
     * @param-out array<string, array{name: string, index: int}> $owners
     */
    private static function rejectEntryPatterns(LayerDefinition|TemplateLayerDefinition $entry, int $entryIndex, array &$owners): void
    {
        $entryName = $entry instanceof TemplateLayerDefinition ? $entry->nameTemplate() : $entry->name();
        $membership = $entry->membership();

        $seenInThisEntry = [];
        foreach ($membership->patterns as $pattern) {
            $normalized = MembershipSpec::patternIdentity($pattern);
            if (isset($seenInThisEntry[$normalized])) {
                continue;
            }
            $seenInThisEntry[$normalized] = true;

            self::claimPattern($normalized, $entryName, $entryIndex, $membership, $owners);
        }
    }

    /**
     * One pattern of one entry: refused behind the entry that owns it, or
     * recorded as its owner when this entry takes every class it names.
     *
     * @param array<string, array{name: string, index: int}> $owners
     *
     * @param-out array<string, array{name: string, index: int}> $owners
     */
    private static function claimPattern(string $pattern, string $entryName, int $entryIndex, MembershipSpec $membership, array &$owners): void
    {
        $owner = $owners[$pattern] ?? null;
        if ($owner === null) {
            if ($membership->ownsItsPatterns()) {
                $owners[$pattern] = ['name' => $entryName, 'index' => $entryIndex];
            }

            return;
        }

        if ($owner['name'] !== $entryName && !$membership->narrowsItsPatterns()) {
            self::refuseCollision($pattern, $owner, $entryName, $entryIndex);
        }
    }

    /**
     * @param array{name: string, index: int} $owner
     */
    private static function refuseCollision(string $pattern, array $owner, string $entryName, int $entryIndex): never
    {
        self::refuse(
            'architecture.layers',
            \sprintf(
                'architecture.layers: pattern "%s" declared in both "%s" (architecture.layers[%d]) and "%s" (architecture.layers[%d]). Under declaration-order matching the second occurrence is unreachable; remove or refine one of them — an "exclude" on "%s" hands the classes it removes to "%s".',
                $pattern,
                $owner['name'],
                $owner['index'],
                $entryName,
                $entryIndex,
                $owner['name'],
                $entryName,
            ),
        );
    }

    /** Builds the refusal noise every throw site in this class shares: a position under the resolved document. */
    private static function refuse(string $position, string $summary): never
    {
        throw ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open(explode('.', $position), $position),
            $summary,
        );
    }
}
