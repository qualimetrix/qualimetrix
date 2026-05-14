<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use InvalidArgumentException;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Core\Architecture\Layer\MatchMode;
use Qualimetrix\Core\Architecture\Layer\TemplateLayerDefinition;

/**
 * Parses and validates the optional {@code exclude:} block inside a single
 * {@code architecture.layers[*]} entry (Phase 2 direction 3 — see ADR 0007).
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
 * The class is stateless: a single entry point
 * ({@see parse()}) drives the flow. Errors surface as
 * {@see ConfigLoadException} with the {@code 'architecture'} config path so
 * the user sees a consistent error namespace.
 *
 * Lives in {@code Configuration/Architecture/Validation/} alongside
 * {@see LayersValidator}; the {@code architecture.layers[*].exclude}
 * sub-tree is co-owned with the layer entry itself, and keeping both
 * validators in the same namespace makes the schema surface easy to find.
 */
final class ExcludeBlockValidator
{
    private const string CONFIG_PATH = 'architecture';

    /**
     * Keys accepted inside an {@code exclude:} block. Mirrors the positive
     * criterion set but without {@code name} (anonymous clause) and without
     * a nested {@code exclude} (single-level filter).
     */
    private const array ALLOWED_EXCLUDE_KEYS = [
        'patterns',
        'suffix',
        'attributes',
        'implements',
        'extends',
        'match',
    ];

    /**
     * Parses the raw {@code exclude:} value into an {@see ExcludeSpec} (or
     * {@code null} when the block is omitted).
     *
     * @param int $index Layer-entry index — used for error path prefixes.
     * @param string $layerName Layer name — used for error path prefixes.
     * @param mixed $value Raw value as it appeared under the {@code exclude:}
     *                     key.
     * @param bool $isTemplate True when the layer name contains capture
     *                         variables. Controls how strictly captures
     *                         inside the exclude block are scrutinised.
     * @param LayerCriterionNormalizer $normalizer Shared per-criterion
     *                                             shape/semantic validator
     *                                             ({@code patterns}/{@code suffix}/
     *                                             FQN-shaped lists and the
     *                                             {@code match} mode).
     *
     * @throws ConfigLoadException On any shape, key, or capture-placement
     *                             violation.
     */
    public static function parse(
        int $index,
        string $layerName,
        mixed $value,
        bool $isTemplate,
        LayerCriterionNormalizer $normalizer,
    ): ?ExcludeSpec {
        if ($value === null) {
            return null;
        }

        self::assertMapShape($index, $layerName, $value);
        \assert(\is_array($value));

        self::rejectUnknownKeys($index, $layerName, $value);

        $criteria = self::normalizeCriteria($index, $layerName, $value, $normalizer);
        self::rejectAllEmptyCriteria($index, $layerName, $criteria);
        self::rejectInvalidCapturePlacements($index, $layerName, $criteria, $isTemplate);

        $mode = $normalizer->normalizeMatchMode($index, $layerName . '.exclude', $value['match'] ?? null);

        return self::buildExcludeSpec($index, $layerName, $criteria, $mode);
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function buildExcludeSpec(int $index, string $layerName, array $criteria, MatchMode $mode): ExcludeSpec
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
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.layers[%d] ("%s"): exclude — %s', $index, $layerName, $e->getMessage()),
                $e,
            );
        }
    }

    private static function assertMapShape(int $index, string $layerName, mixed $value): void
    {
        if (!\is_array($value)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d] ("%s"): "exclude" must be a non-empty map of criterion keys (patterns / suffix / attributes / implements / extends / match), got %s.',
                    $index,
                    $layerName,
                    get_debug_type($value),
                ),
            );
        }

        // `array_is_list([])` returns true, so this branch also rejects
        // empty arrays ({@code exclude: []}) — omit the key entirely to
        // leave the clause undeclared.
        if (array_is_list($value)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.layers[%d] ("%s"): "exclude" must be a non-empty map of criterion keys (patterns / suffix / attributes / implements / extends / match), got %s.',
                    $index,
                    $layerName,
                    $value === [] ? 'empty list' : 'sequential list',
                ),
            );
        }
    }

    /**
     * @param array<string, mixed> $exclude
     */
    private static function rejectUnknownKeys(int $index, string $layerName, array $exclude): void
    {
        $unknown = array_diff(array_keys($exclude), self::ALLOWED_EXCLUDE_KEYS);
        if ($unknown === []) {
            return;
        }

        $reservedNested = \in_array('exclude', $unknown, true);
        $quoted = '"' . implode('", "', $unknown) . '"';
        $allowed = '"' . implode('", "', self::ALLOWED_EXCLUDE_KEYS) . '"';
        $message = \sprintf(
            'architecture.layers[%d] ("%s"): unknown key(s) %s inside "exclude". Allowed keys: %s.',
            $index,
            $layerName,
            $quoted,
            $allowed,
        );

        if ($reservedNested) {
            $message .= ' Nested "exclude" is not supported — the exclude filter is single-level.';
        }

        throw new ConfigLoadException(self::CONFIG_PATH, $message);
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>}
     */
    private static function normalizeCriteria(int $index, string $layerName, array $value, LayerCriterionNormalizer $normalizer): array
    {
        $excludePath = $layerName . '.exclude';

        return [
            'patterns' => $normalizer->normalizePatternList($index, $excludePath, $value['patterns'] ?? null),
            'suffix' => $normalizer->normalizeSuffixList($index, $excludePath, $value['suffix'] ?? null),
            'attributes' => $normalizer->normalizeFqnList($index, $excludePath, 'attributes', $value['attributes'] ?? null),
            'implements' => $normalizer->normalizeFqnList($index, $excludePath, 'implements', $value['implements'] ?? null),
            'extends' => $normalizer->normalizeFqnList($index, $excludePath, 'extends', $value['extends'] ?? null),
        ];
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function rejectAllEmptyCriteria(int $index, string $layerName, array $criteria): void
    {
        if (array_filter($criteria) !== []) {
            return;
        }

        throw new ConfigLoadException(
            self::CONFIG_PATH,
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
    private static function rejectInvalidCapturePlacements(int $index, string $layerName, array $criteria, bool $isTemplate): void
    {
        if ($isTemplate) {
            self::rejectCapturesInKinds(
                $index,
                $layerName,
                $criteria,
                ['suffix', 'attributes', 'implements', 'extends'],
                'captures are only allowed in exclude.patterns (suffix/attributes/implements/extends are fixed strings).',
            );

            return;
        }

        self::rejectCapturesInKinds(
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

                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf(
                        'architecture.layers[%d] ("%s"): exclude.%s entry at index %d "%s" contains a capture variable — %s',
                        $index,
                        $layerName,
                        $kind,
                        $entryIndex,
                        $entry,
                        $rejectionReason,
                    ),
                );
            }
        }
    }
}
