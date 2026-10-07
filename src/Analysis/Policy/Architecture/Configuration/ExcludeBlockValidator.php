<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\NamedType;
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
        CarriedValueForm::rejectInvalidExcludeCapturePlacements($index, $layerName, $criteria, $isTemplate, $value);

        $mode = $normalizer->normalizeMatchMode($index, $layerName . '.exclude', $value->child('match'));

        return self::buildExcludeSpec($index, $layerName, $criteria, $mode, $value);
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, member_attributes: list<string>, implements: list<string>, extends: list<string>, named_types: list<NamedType>} $criteria
     */
    private static function buildExcludeSpec(int $index, string $layerName, array $criteria, MatchMode $mode, SectionSpot $spot): ExcludeSpec
    {
        try {
            return new ExcludeSpec(
                patterns: $criteria['patterns'],
                suffix: $criteria['suffix'],
                attributes: $criteria['attributes'],
                memberAttributes: $criteria['member_attributes'],
                implements: $criteria['implements'],
                extends: $criteria['extends'],
                mode: $mode,
                namedTypes: $criteria['named_types'],
            );
        } catch (InvalidArgumentException $e) {
            throw $spot->refusal(\sprintf('architecture.layers[%d] ("%s"): exclude — %s', $index, $layerName, $e->getMessage()));
        }
    }

    /**
     * @return array{patterns: list<string>, suffix: list<string>, attributes: list<string>, member_attributes: list<string>, implements: list<string>, extends: list<string>, named_types: list<NamedType>}
     */
    private static function normalizeCriteria(int $index, string $layerName, SectionSpot $value, LayerCriterionNormalizer $normalizer): array
    {
        $excludePath = $layerName . '.exclude';

        $attributes = $normalizer->normalizeNamedTypeList($index, $excludePath, 'attributes', $value->child('attributes'));
        $memberAttributes = $normalizer->normalizeNamedTypeList($index, $excludePath, 'member_attributes', $value->child('member_attributes'));
        $implements = $normalizer->normalizeNamedTypeList($index, $excludePath, 'implements', $value->child('implements'));
        $extends = $normalizer->normalizeNamedTypeList($index, $excludePath, 'extends', $value->child('extends'));

        return [
            'patterns' => $normalizer->normalizePatternList($index, $excludePath, $value->child('patterns')),
            'suffix' => $normalizer->normalizeSuffixList($index, $excludePath, $value->child('suffix')),
            'attributes' => self::typeNames($attributes),
            'member_attributes' => self::typeNames($memberAttributes),
            'implements' => self::typeNames($implements),
            'extends' => self::typeNames($extends),
            'named_types' => [...$attributes, ...$memberAttributes, ...$implements, ...$extends],
        ];
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, member_attributes: list<string>, implements: list<string>, extends: list<string>, named_types: list<NamedType>} $criteria
     */
    private static function rejectAllEmptyCriteria(int $index, string $layerName, array $criteria, SectionSpot $spot): void
    {
        if (array_filter($criteria) !== []) {
            return;
        }

        throw $spot->refusal(
            \sprintf(
                'architecture.layers[%d] ("%s"): "exclude" must declare at least one of "patterns", "suffix", "attributes", "member_attributes", "implements" or "extends" (omit the "exclude" key to leave it undeclared).',
                $index,
                $layerName,
            ),
        );
    }

    /** @param list<NamedType> $types
     * @return list<string>
     */
    private static function typeNames(array $types): array
    {
        return array_map(static fn(NamedType $type): string => $type->fqn, $types);
    }

}
