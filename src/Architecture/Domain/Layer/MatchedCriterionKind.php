<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

/**
 * Classifies the membership criterion that produced a layer match.
 *
 * Used as a tag on {@see MatchedCriterion} so the violation message and the
 * {@code architecture.potential-shadow} diagnostic can report WHICH criterion
 * caught the class under OR semantics ({@code match: any}) — e.g. "matched by
 * suffix 'Repository'" vs. "matched by pattern 'App\\Repository'".
 *
 * The enum values use the singular form ({@code pattern}, {@code attribute})
 * for diagnostic readability — "matched by pattern X" reads better than
 * "matched by patterns X". The corresponding YAML keys under
 * {@code architecture.layers[*]} use the plural form for the LIST kinds
 * ({@code patterns}, {@code attributes}) and singular elsewhere. Translation
 * between the two surfaces is currently confined to the relevant validators
 * and message builders; if a third surface ever needs the mapping, lift it
 * into a dedicated translator.
 */
enum MatchedCriterionKind: string
{
    case Pattern = 'pattern';
    case Suffix = 'suffix';
    case Attribute = 'attribute';
    case Implements = 'implements';
    case Extends = 'extends';
}
