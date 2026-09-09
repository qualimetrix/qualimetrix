<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;

/**
 * Rejects duplicate {@code patterns} declared across different
 * {@code architecture.layers[*]} entries. Under declaration-order semantics
 * any class matching the duplicate would always belong to the earlier
 * entry — the second occurrence is unreachable and is always a
 * configuration mistake.
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
 * **Mode-aware skip (H1 remediation).** When at least one of the two
 * colliding entries declares {@code match: all} together with a non-empty
 * non-pattern criterion (suffix / attributes / implements / extends), the
 * pattern overlap is NOT necessarily unreachable: the narrowing entry
 * only claims the subset of pattern matches that also satisfy the extra
 * criteria, leaving room for the sibling entry to legitimately catch the
 * residue. The check is skipped in that case to avoid the false-positive
 * documented in the architecture-rules remediation plan (Phase 1.2).
 *
 * Trade-off: a {@code match: any} entry sitting AFTER a {@code match: all}
 * narrowing entry on the same pattern is technically reachable, while a
 * {@code match: all} narrowing entry sitting AFTER a {@code match: any}
 * blanket entry on the same pattern is technically unreachable. The skip
 * accepts the latter false negative to eliminate the former false
 * positive — losing a "rare unreachable layer" warning is less harmful
 * than rejecting a valid config. Order-symmetric "one or both" predicate
 * keeps the rule simple for users to reason about.
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
     * @param array<string, array{name: string, index: int, narrows: bool}> $owners
     *
     * @param-out array<string, array{name: string, index: int, narrows: bool}> $owners
     */
    private static function rejectEntryPatterns(LayerDefinition|TemplateLayerDefinition $entry, int $entryIndex, array &$owners): void
    {
        $entryName = $entry instanceof TemplateLayerDefinition ? $entry->nameTemplate() : $entry->name();
        $membership = $entry->membership();
        $entryNarrows = self::narrowsByNonPatternCriteria($membership);

        $seenInThisEntry = [];
        foreach ($membership->patterns as $pattern) {
            $normalized = rtrim($pattern, '\\');
            if (isset($seenInThisEntry[$normalized])) {
                continue;
            }
            $seenInThisEntry[$normalized] = true;

            self::rejectPatternCollision($normalized, $entryName, $entryIndex, $entryNarrows, $owners);
        }
    }

    /**
     * One pattern's own collision check, split out of
     * {@see self::rejectEntryPatterns()} to keep both methods' cyclomatic
     * weight readable.
     *
     * @param array<string, array{name: string, index: int, narrows: bool}> $owners
     *
     * @param-out array<string, array{name: string, index: int, narrows: bool}> $owners
     */
    private static function rejectPatternCollision(string $normalized, string $entryName, int $entryIndex, bool $isNarrowing, array &$owners): void
    {
        if (isset($owners[$normalized]) && $owners[$normalized]['name'] !== $entryName) {
            if ($owners[$normalized]['narrows'] || $isNarrowing) {
                // Either the earlier owner narrows its pattern matches
                // with non-pattern AND-criteria, or this entry does —
                // the second occurrence is not necessarily unreachable.
                return;
            }

            self::refuse(
                'architecture.layers',
                \sprintf(
                    'architecture.layers: pattern "%s" declared in both "%s" (architecture.layers[%d]) and "%s" (architecture.layers[%d]). Under declaration-order matching the second occurrence is unreachable; remove or refine one of them.',
                    $normalized,
                    $owners[$normalized]['name'],
                    $owners[$normalized]['index'],
                    $entryName,
                    $entryIndex,
                ),
            );
        }

        if (!isset($owners[$normalized])) {
            $owners[$normalized] = ['name' => $entryName, 'index' => $entryIndex, 'narrows' => $isNarrowing];
        }
    }

    /**
     * True when the entry declares {@code match: all} AND carries at least
     * one non-empty non-pattern criterion (suffix / attributes / implements /
     * extends). Such an entry only claims the subset of pattern matches that
     * also satisfy the extra criteria — its patterns can legitimately overlap
     * with siblings without rendering anyone unreachable.
     *
     * {@code match: any} entries never narrow: their patterns alone are
     * sufficient to claim every match.
     */
    private static function narrowsByNonPatternCriteria(MembershipSpec $membership): bool
    {
        if ($membership->mode !== MatchMode::All) {
            return false;
        }

        return $membership->suffix !== []
            || $membership->attributes !== []
            || $membership->implements !== []
            || $membership->extends !== [];
    }

    /** Builds the refusal noise every throw site in this class shares: a position under the resolved document. */
    private static function refuse(string $position, string $summary): never
    {
        throw ConfigurationRefusal::at(
            ConfigurationOrigin::of(ConfigurationSource::Resolved),
            RefusedPosition::open(explode('.', $position), $position),
            $summary,
        );
    }
}
