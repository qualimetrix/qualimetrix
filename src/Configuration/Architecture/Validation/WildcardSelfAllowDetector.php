<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Architecture\Allow\AllowListEntry;

/**
 * Scans the parsed allow-list for {@code 'foo-*' → 'foo-*'} self-glob entries
 * and emits a deferred warning. These rarely express the user's intent: the
 * entry permits every layer matching {@code foo-*} (including the source's own
 * instance namespace) to depend on every other matching layer, effectively
 * collapsing the partition the template was created to enforce.
 *
 * **Scope.** Only entries whose source AND target are both
 * {@see \Qualimetrix\Core\Architecture\Allow\SelectorKind::Glob} contribute.
 * Captured-on-both-sides entries enforce binding identity via
 * {@see \Qualimetrix\Core\Architecture\Allow\LayerSelector::matchesTarget()};
 * glob-source paired with captured-target is rejected at config load by
 * {@see AllowValidator}.
 *
 * **Overlap criterion: byte-for-byte string equality** between the source and
 * target glob patterns. The detector is deliberately conservative: equivalent
 * patterns spelled differently ({@code 'domain-*'} vs {@code 'domain-?*'}) and
 * strict-superset relationships ({@code 'domain-a*' → 'domain-*'}) are NOT
 * flagged. fnmatch-overlap detection would either generate noisy false
 * positives (every {@code 'foo-*' → 'foo-bar-*'} pair, where the user is
 * intentionally widening) or require costly pattern-language analysis. The
 * canonical foot-gun is the literal self-reference; non-canonical spellings
 * are out of scope.
 *
 * **Silencing.** Granularity is **per target**, not per entry:
 * {@code allow_cross_instance: true} on a specific long-form target silences
 * only that target's contribution. An entry with one silenced and one
 * un-silenced self-glob target still warns about the un-silenced one (the
 * user opted out of binding-identity enforcement for one target, not the
 * entire entry).
 */
final class WildcardSelfAllowDetector
{
    /**
     * @param list<AllowListEntry> $entries
     * @param list<DeferredWarning> $warnings Accumulator, mutated by reference for warning collection.
     */
    public function detect(array $entries, array &$warnings): void
    {
        $patterns = [];
        foreach ($entries as $entry) {
            if (!$entry->source->isGlob()) {
                continue;
            }
            $sourcePattern = $entry->source->originalString();
            foreach ($entry->targets as $target) {
                if (!$target->target->isGlob()) {
                    continue;
                }
                if ($target->allowCrossInstance) {
                    continue;
                }
                if ($target->target->originalString() !== $sourcePattern) {
                    continue;
                }
                $patterns[$sourcePattern] = true;
            }
        }

        if ($patterns === []) {
            return;
        }

        $rendered = implode(', ', array_map(
            static fn(string $pattern): string => "'{$pattern}'",
            array_keys($patterns),
        ));

        $warnings[] = DeferredWarning::warning(\sprintf(
            'architecture.allow: wildcard-self-allow detected on entry(s) %s. ' .
            'A glob-on-both-sides entry permits every matching layer to depend on every other matching layer, ' .
            "including cross-instance edges. Add 'allow_cross_instance: true' on the long-form target to silence " .
            'this warning if that is intended, or replace the glob with a captured selector (e.g. ' .
            "'app-{module}' → 'domain-{module}') to enforce binding identity.",
            $rendered,
        ));
    }
}
