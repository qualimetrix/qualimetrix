<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

/**
 * The one question a merge, and a threshold read, both ask of a value: was it
 * actually written, or does its slot merely carry the compiled default?
 *
 * A key is written by carrying any value other than `null`. `null` is what an
 * author's `~` becomes once YAML (or a CLI/preset door) parses it — the
 * author left the slot to the default, exactly as the rest of the document
 * reads it, so it is indistinguishable from having written nothing at all.
 *
 * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser} already asks this question
 * through `isset($config[$key])`, which is the same predicate for a key known
 * to be present: `isset()` on an array key that exists is false exactly when
 * the value is `null`. Both configuration merge sites
 * ({@see RuleOptionsFactory}, {@see \Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver})
 * ask the same question one layer up — whether an overlay's `null` should be
 * allowed to erase a value the layer below it wrote — and this is the one
 * place that answer is defined, so parser and merge cannot silently drift
 * into two different meanings for the same symbol.
 */
final class RuleOptionValueWrittenness
{
    public static function isWritten(mixed $value): bool
    {
        return $value !== null;
    }
}
