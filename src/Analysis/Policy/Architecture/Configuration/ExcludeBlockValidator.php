<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;

/**
 * Parses and validates the optional {@code exclude:} block inside a single
 * {@code architecture.layers[*]} entry (see ADR 0059).
 *
 * Mirrors {@see LayersValidator}'s positive-criteria validator: each
 * criterion list goes through the same per-kind shape and FQN/suffix
 * semantic checks. Differences:
 *
 * - The block has no {@code name} key (the exclude clause is anonymous).
 * - The block accepts no nested {@code exclude} (single-level filter only).
 * - Capture variables ({@code &#123;var&#125;}) are accepted only in
 *   {@code exclude.patterns} for template layers, and not at all for static
 *   layers. Cross-template variable scoping (an exclude variable must
 *   already be declared by the template's name or capture-producing
 *   patterns) is enforced separately by
 *   {@see TemplateLayerDefinition}'s constructor.
 *
 * The configuration engine has already recognised the block's keys and
 * judged its shape: a block that writes nothing — {@code exclude: ~},
 * {@code exclude: {}} — is no clause at all, like any other empty map.
 *
 * The class is stateless: a single entry point
 * ({@see parse()}) drives the flow. Errors surface as
 * {@see ConfigurationRefusal} naming the layer that wrote the block.
 *
 * Lives in {@code Configuration/Architecture/Validation/} alongside
 * {@see LayersValidator}; the {@code architecture.layers[*].exclude}
 * sub-tree is co-owned with the layer entry itself, and keeping both
 * validators in the same namespace makes the schema surface easy to find.
 */
final class ExcludeBlockValidator
{
    /**
     * Parses the resolved {@code exclude:} block into an {@see ExcludeSpec}
     * (or {@code null} when the block is not written).
     *
     * @param int $index Layer-entry index — used for error path prefixes.
     * @param string $layerName Layer name — used for error path prefixes.
     * @param SectionSpot $value The {@code exclude:} block of the entry.
     * @param bool $isTemplate True when the layer name contains capture
     *                         variables. Controls how strictly captures
     *                         inside the exclude block are scrutinised.
     * @param LayerCriterionNormalizer $normalizer Shared per-criterion
     *                                             shape/semantic validator
     *                                             ({@code patterns}/{@code suffix}/
     *                                             FQN-shaped lists and the
     *                                             {@code match} mode).
     *
     * @throws ConfigurationRefusal On a block that writes no criterion beside
     *                              `match`, or misplaces a capture.
     */
    public static function parse(
        int $index,
        string $layerName,
        SectionSpot $value,
        bool $isTemplate,
        LayerCriterionNormalizer $normalizer,
    ): ?ExcludeSpec {
        if (!$value->isWritten()) {
            return null;
        }

        $criteria = self::normalizeCriteria($index, $layerName, $value, $normalizer);
        self::rejectAllEmptyCriteria($index, $layerName, $criteria, $value);
        self::rejectInvalidCapturePlacements($index, $layerName, $criteria, $isTemplate, $value);

        $mode = $normalizer->normalizeMatchMode($index, $layerName . '.exclude', $value->child('match'));

        return self::buildExcludeSpec($index, $layerName, $criteria, $mode, $value);
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function buildExcludeSpec(int $index, string $layerName, array $criteria, MatchMode $mode, SectionSpot $spot): ExcludeSpec
    {
        try {
            return new ExcludeSpec(
                patterns: $criteria['patterns'],
                suffix: $criteria['suffix'],
                attributes: $criteria['attributes'],
                implements: $criteria['implements'],
                extends: $criteria['extends'],
                mode: $mode,
            );
        } catch (InvalidArgumentException $e) {
            throw $spot->refusal(\sprintf('architecture.layers[%d] ("%s"): exclude — %s', $index, $layerName, $e->getMessage()));
        }
    }

    /**
     * @return array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>}
     */
    private static function normalizeCriteria(int $index, string $layerName, SectionSpot $value, LayerCriterionNormalizer $normalizer): array
    {
        $excludePath = $layerName . '.exclude';

        return [
            'patterns' => $normalizer->normalizePatternList($index, $excludePath, $value->child('patterns')),
            'suffix' => $normalizer->normalizeSuffixList($index, $excludePath, $value->child('suffix')),
            'attributes' => $normalizer->normalizeFqnList($index, $excludePath, 'attributes', $value->child('attributes')),
            'implements' => $normalizer->normalizeFqnList($index, $excludePath, 'implements', $value->child('implements')),
            'extends' => $normalizer->normalizeFqnList($index, $excludePath, 'extends', $value->child('extends')),
        ];
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function rejectAllEmptyCriteria(int $index, string $layerName, array $criteria, SectionSpot $spot): void
    {
        if (array_filter($criteria) !== []) {
            return;
        }

        throw $spot->refusal(
            \sprintf(
                'architecture.layers[%d] ("%s"): "exclude" must declare at least one of "patterns", "suffix", "attributes", "implements" or "extends" (omit the "exclude" key to leave it undeclared).',
                $index,
                $layerName,
            ),
        );
    }

    /**
     * For static (non-template) layers, captures are rejected anywhere in
     * the exclude block — there is no name template to bind variables.
     *
     * For template layers, captures are accepted in
     * {@code exclude.patterns} only (mirroring the positive-side carve-out
     * documented on {@see TemplateLayerDefinition}); captures inside
     * {@code suffix}/{@code attributes}/{@code implements}/{@code extends}
     * are rejected with a "wrong place" error.
     *
     * Cross-template variable scoping (every exclude variable must be
     * declared by the template) is enforced by
     * {@see TemplateLayerDefinition} at construction.
     *
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    public static function rejectInvalidCapturePlacements(int $index, string $layerName, array $criteria, bool $isTemplate, SectionSpot $spot): void
    {
        if ($isTemplate) {
            self::rejectCapturesInKinds(
                $spot,
                $index,
                $layerName,
                $criteria,
                ['suffix', 'attributes', 'implements', 'extends'],
                'captures are only allowed in exclude.patterns (suffix/attributes/implements/extends are fixed strings).',
            );

            return;
        }

        self::rejectCapturesInKinds(
            $spot,
            $index,
            $layerName,
            $criteria,
            ['patterns', 'suffix', 'attributes', 'implements', 'extends'],
            \sprintf(
                'capture variables in exclude are only allowed for template layers (a name containing {var}); the layer name "%s" has none.',
                $layerName,
            ),
        );
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     * @param list<string> $kindsToScan
     */
    private static function rejectCapturesInKinds(
        SectionSpot $spot,
        int $index,
        string $layerName,
        array $criteria,
        array $kindsToScan,
        string $rejectionReason,
    ): void {
        foreach ($kindsToScan as $kind) {
            foreach ($criteria[$kind] as $entryIndex => $entry) {
                if (!TemplateLayerDefinition::containsCaptureVariable($entry)) {
                    continue;
                }

                $kindSpot = $spot->child($kind);
                throw (\is_array($kindSpot->value()) ? $kindSpot->child($entryIndex) : $kindSpot)->refusal(
                    \sprintf(
                        'architecture.layers[%d] ("%s"): exclude.%s entry at index %d "%s" contains a capture variable — %s',
                        $index,
                        $layerName,
                        $kind,
                        $entryIndex,
                        $entry,
                        $rejectionReason,
                    ),
                    written: $entry,
                );
            }
        }
    }
}
