<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures;

/**
 * One set of map lines, checked against both readers of the format.
 *
 * The finding gate reads `finding-gate/maps/channels.tsv` with
 * `QmxFindingGate\Tsv`, which lives in `scripts/` and is not on the
 * production autoloader; the product reads the same shape with
 * {@see \Qualimetrix\Analysis\Policy\Baseline\ChannelRenameMap}. They are two
 * implementations, so "the same format" is a claim, and this corpus is what
 * makes it checkable: every case states what each consumer does with it, and
 * the five cases where the two deliberately disagree say why in the same
 * place.
 *
 * The cases state the expected behavior and explain the deliberate
 * differences between the readers.
 */
final class ChannelRenameTsvCorpus
{
    /**
     * `renames` is the map the product's reader must produce from `contents`;
     * it is written by hand here rather than read back off the reader, and it
     * is meaningless — and empty — on a case the product refuses.
     *
     * @return list<array{id: string, renames: array<string, string>, contents: string, product: bool, gate: bool, note: string}>
     */
    public static function cases(): array
    {
        return [
            [
                'id' => 'header-only',
                'renames' => [],
                'contents' => "old\tnew\treason\n",
                'product' => true,
                'gate' => true,
                'note' => 'A map that declares nothing is a map, not a defect.',
            ],
            [
                'id' => 'one-row',
                'renames' => ['complexity.cyclomatic' => 'complexity.ccn'],
                'contents' => "old\tnew\treason\ncomplexity.cyclomatic\tcomplexity.ccn\tfixture reason\n",
                'product' => true,
                'gate' => true,
                'note' => 'The ordinary row.',
            ],
            [
                'id' => 'crlf-line-endings',
                'renames' => ['complexity.cyclomatic' => 'complexity.ccn'],
                'contents' => "old\tnew\treason\r\ncomplexity.cyclomatic\tcomplexity.ccn\ttest reason\r\n",
                'product' => true,
                'gate' => true,
                'note' => 'A trailing \r is stripped per line: a CRLF checkout is not a defect.',
            ],
            [
                'id' => 'comment-and-blank-lines',
                'renames' => ['complexity.cyclomatic' => 'complexity.ccn'],
                'contents' => "old\tnew\treason\n\n# a note\ncomplexity.cyclomatic\tcomplexity.ccn\ttest reason\n\n",
                'product' => true,
                'gate' => true,
                'note' => 'Blank lines and # comments are skipped.',
            ],
            [
                'id' => 'wrong-header',
                'renames' => [],
                'contents' => "from\tto\treason\ncomplexity.cyclomatic\tcomplexity.ccn\ttest reason\n",
                'product' => false,
                'gate' => false,
                'note' => 'The header names the columns; a different one is a different file.',
            ],
            [
                'id' => 'header-with-extra-column',
                'renames' => [],
                'contents' => "old\tnew\treason\tnote\ncomplexity.cyclomatic\tcomplexity.ccn\ttest reason\tn\n",
                'product' => false,
                'gate' => false,
                'note' => 'Four columns is not this format.',
            ],
            [
                'id' => 'row-missing-a-field',
                'renames' => [],
                'contents' => "old\tnew\treason\ncomplexity.cyclomatic\tcomplexity.ccn\n",
                'product' => false,
                'gate' => false,
                'note' => 'A row without a reason is a rename nobody justified.',
            ],
            [
                'id' => 'leading-space-in-old',
                'renames' => [],
                'contents' => "old\tnew\treason\n complexity.ccn\tcomplexity.ccn\ttest reason\n",
                'product' => false,
                'gate' => false,
                'note' => 'Matching is exact equality, so an invisible edge matches nothing.',
            ],
            [
                'id' => 'trailing-space-in-new',
                'renames' => [],
                'contents' => "old\tnew\treason\ncomplexity.cyclomatic\tcomplexity.ccn \ttest reason\n",
                'product' => false,
                'gate' => false,
                'note' => 'The same rule on the other half of the row.',
            ],
            [
                'id' => 'renames-nothing',
                'renames' => [],
                'contents' => "old\tnew\treason\ncomplexity.ccn\tcomplexity.ccn\ttest reason\n",
                'product' => false,
                'gate' => false,
                'note' => 'Both sides equal: a declaration that states no rename.',
            ],
            [
                'id' => 'two-rows-for-one-old',
                'renames' => [],
                'contents' => "old\tnew\treason\na.b\tc.d\ttest reason\na.b\te.f\ttest reason\n",
                'product' => false,
                'gate' => false,
                'note' => 'What the old name carries to is then undecidable.',
            ],
            [
                'id' => 'chain',
                'renames' => [],
                'contents' => "old\tnew\treason\na.b\tc.d\ttest reason\nc.d\te.f\ttest reason\n",
                'product' => false,
                'gate' => false,
                'note' => 'The result would depend on the order the rows were applied in.',
            ],
            [
                'id' => 'collapse-two-olds-onto-one-new',
                'renames' => [],
                'contents' => "old\tnew\treason\na.b\tc.d\ttest reason\ne.f\tc.d\ttest reason\n",
                'product' => false,
                'gate' => true,
                'note' => 'Divergence. The gate applies channels.tsv forwards only, and forwards a collapse '
                    . 'is a correct statement about two names becoming one. A carry has to write a file, and '
                    . 'a file cannot hold two accepted ceilings for one identity.',
            ],
            [
                'id' => 'the-same-row-twice',
                'renames' => [],
                'contents' => "old\tnew\treason\na.b\tc.d\ttest reason\na.b\tc.d\ttest reason\n",
                'product' => false,
                'gate' => true,
                'note' => 'Divergence. The gate groups rows by (old, new) so that one name may be declared '
                    . 'in two maps at once, which makes a repeat inside one map indistinguishable from that. '
                    . 'A carry reads one map and treats a repeated old as the ambiguity it is.',
            ],
            [
                'id' => 'retired-pair-spelling',
                'renames' => [],
                'contents' => "old\tnew\treason\ncomplexity#complexity.ccn\tcomplexity.ccn\ttest reason\n",
                'product' => false,
                'gate' => true,
                'note' => 'Divergence. The gate still expands the retired rule#code spelling, because a '
                    . 'reference tree predating the change publishes it. A baseline never did: its channel '
                    . 'field holds a code, and FindingChannel refuses the pair spelling outright.',
            ],
            [
                'id' => 'empty-new-field',
                'renames' => [],
                'contents' => "old\tnew\treason\ncomplexity.cyclomatic\t\ttest reason\n",
                'product' => false,
                'gate' => true,
                'note' => 'Divergence. The gate checks a field for whitespace edges, not for being a name; '
                    . 'the carry constructs a FindingChannel from it and an empty channel does not exist.',
            ],
            [
                'id' => 'name-carrying-a-level-separator',
                'renames' => [],
                'contents' => "old\tnew\treason\ncomplexity.cyclomatic\tcomplexity.ccn:method\ttest reason\n",
                'product' => false,
                'gate' => true,
                'note' => 'Divergence, same shape as the one above: ":" addresses a level beside a channel '
                    . 'name and can never be part of one, which only the product type knows.',
            ],
        ];
    }
}
