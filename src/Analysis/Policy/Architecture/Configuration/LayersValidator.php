<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerLifecycle;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;
use Throwable;

/**
 * Parses and validates the {@code architecture.layers} sub-tree.
 *
 * Accepts the long-form ordered list with five criterion kinds (Phase 2
 * direction 1) plus the optional {@code exclude:} block (direction 3):
 *
 * ```yaml
 * layers:
 *   - name: repository
 *     patterns: ['App\Repository\**']
 *     suffix: 'Repository'
 *     implements: 'Doctrine\Persistence\ObjectRepository'
 *     match: any
 *     exclude:
 *       patterns: ['App\Repository\Legacy\**']
 * ```
 *
 * Produces a typed {@see LayerRegistry} preserving declaration order.
 *
 * Per-criterion shape validation is delegated to
 * {@see LayerCriterionNormalizer}; the {@code exclude:} sub-block is
 * delegated to {@see ExcludeBlockValidator}; cross-entry duplicate-pattern
 * reachability is delegated to {@see DuplicatePatternRejector}. All three
 * helpers stay inside the same namespace so the schema surface is
 * co-located.
 *
 * All errors surface as {@see ConfigurationRefusal} addressed to the
 * resolved document.
 */
final class LayersValidator
{
    private const array ALLOWED_ENTRY_KEYS = [
        'name',
        'patterns',
        'suffix',
        'attributes',
        'implements',
        'extends',
        'match',
        'exclude',
        'pending',
    ];

    private readonly LayerCriterionNormalizer $normalizer;

    public function __construct()
    {
        $this->normalizer = new LayerCriterionNormalizer();
    }

    /** Builds the refusal noise every throw site in this class shares: a position under the resolved document. */
    private static function refuse(string $position, string $summary, ?Throwable $previous = null): never
    {
        throw ConfigurationRefusal::at(
            ConfigurationOrigin::of(ConfigurationSource::Resolved),
            RefusedPosition::open(explode('.', $position), $position),
            $summary,
            $previous,
        );
    }

    /**
     * Parses the raw {@code layers} value into the declaration-order list of
     * static {@see LayerDefinition}s and parameterised
     * {@see TemplateLayerDefinition}s.
     *
     * Templates are recognised by the presence of capture variables in the
     * {@code name:} field per the {@see TemplateLayerDefinition} grammar.
     * Cross-template duplicate detection (e.g. two templates expanding to the
     * same instance name) happens at expansion time in
     * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage}, not here.
     *
     * @return list<LayerDefinition|TemplateLayerDefinition>
     */
    public function validate(mixed $layersRaw): array
    {
        $entries = $this->buildLayerEntries($layersRaw);
        DuplicatePatternRejector::reject($entries);

        return $entries;
    }

    /**
     * @return list<LayerDefinition|TemplateLayerDefinition>
     */
    private function buildLayerEntries(mixed $layersRaw): array
    {
        if ($layersRaw === [] || $layersRaw === null) {
            return [];
        }

        if (!\is_array($layersRaw)) {
            self::refuse(
                'architecture.layers',
                'architecture.layers: must be an ordered list of layer entries, got ' . get_debug_type($layersRaw) . '.',
            );
        }

        if (!array_is_list($layersRaw)) {
            self::refuse(
                'architecture.layers',
                'architecture.layers: must be an ordered list of layer entries (each entry an object with "name" and at least one criterion key), not a map. '
                . 'See ADR 0006 for the schema change rationale.',
            );
        }

        $entries = [];
        $seenNames = [];
        foreach ($layersRaw as $index => $entry) {
            $entries[] = $this->buildSingleLayerEntry($index, $entry, $seenNames);
        }

        return $entries;
    }

    /**
     * @param array<string, true> $seenNames
     *
     * @param-out array<string, true> $seenNames
     */
    private function buildSingleLayerEntry(int $index, mixed $entry, array &$seenNames): LayerDefinition|TemplateLayerDefinition
    {
        $entry = self::ensureEntryIsAssociativeArray($index, $entry);
        self::rejectUnknownKeys($index, $entry);

        $name = self::extractValidName($index, $entry);
        self::rejectDuplicateName($index, $name, $seenNames);
        $seenNames[$name] = true;

        $criteria = $this->normalizeCriteria($index, $name, $entry);
        $mode = $this->normalizer->normalizeMatchMode($index, $name, $entry['match'] ?? null);
        $isTemplate = TemplateLayerDefinition::containsCaptureVariable($name);
        $exclude = ExcludeBlockValidator::parse($index, $name, $entry['exclude'] ?? null, $isTemplate, $this->normalizer);
        $lifecycle = $this->normalizer->normalizeLifecycle($index, $name, $entry, $isTemplate);

        if ($isTemplate) {
            return self::buildTemplateDefinition($index, $name, $criteria, $mode, $exclude);
        }

        return self::buildMembershipDefinition($index, $name, $criteria, $mode, $exclude, $lifecycle);
    }

    /**
     * Constructs a {@see TemplateLayerDefinition} from a parsed entry whose
     * name contains capture variables. Catches the construction-time
     * invariant violations (empty name, variable in name not bound by any
     * capture-producing pattern, invalid capture grammar, undeclared
     * exclude variables) and rewraps them as {@see ConfigurationRefusal} so
     * the user sees a config-layer error.
     *
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function buildTemplateDefinition(int $index, string $nameTemplate, array $criteria, MatchMode $mode, ?ExcludeSpec $exclude): TemplateLayerDefinition
    {
        self::rejectAllEmptyCriteria($index, $nameTemplate, $criteria);

        try {
            return new TemplateLayerDefinition(
                $nameTemplate,
                new MembershipSpec(
                    patterns: $criteria['patterns'],
                    suffix: $criteria['suffix'],
                    attributes: $criteria['attributes'],
                    implements: $criteria['implements'],
                    extends: $criteria['extends'],
                    mode: $mode,
                    exclude: $exclude,
                ),
            );
        } catch (InvalidArgumentException $e) {
            self::refuse(
                \sprintf('architecture.layers[%d]', $index),
                \sprintf('architecture.layers[%d] ("%s"): %s', $index, $nameTemplate, $e->getMessage()),
                $e,
            );
        }
    }

    /**
     * Collects the five criterion lists from a single layer-entry map.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>}
     */
    private function normalizeCriteria(int $index, string $name, array $entry): array
    {
        return [
            'patterns' => $this->normalizer->normalizePatternList($index, $name, $entry['patterns'] ?? null),
            'suffix' => $this->normalizer->normalizeSuffixList($index, $name, $entry['suffix'] ?? null),
            'attributes' => $this->normalizer->normalizeFqnList($index, $name, 'attributes', $entry['attributes'] ?? null),
            'implements' => $this->normalizer->normalizeFqnList($index, $name, 'implements', $entry['implements'] ?? null),
            'extends' => $this->normalizer->normalizeFqnList($index, $name, 'extends', $entry['extends'] ?? null),
        ];
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function buildMembershipDefinition(int $index, string $name, array $criteria, MatchMode $mode, ?ExcludeSpec $exclude, LayerLifecycle $lifecycle): LayerDefinition
    {
        self::rejectAllEmptyCriteria($index, $name, $criteria);

        try {
            return new LayerDefinition(
                $name,
                new MembershipSpec(
                    patterns: $criteria['patterns'],
                    suffix: $criteria['suffix'],
                    attributes: $criteria['attributes'],
                    implements: $criteria['implements'],
                    extends: $criteria['extends'],
                    mode: $mode,
                    exclude: $exclude,
                ),
                lifecycle: $lifecycle,
            );
        } catch (InvalidLayerDefinitionException | InvalidArgumentException $e) {
            self::refuse(
                \sprintf('architecture.layers[%d]', $index),
                \sprintf('architecture.layers[%d] ("%s"): %s', $index, $name, $e->getMessage()),
                $e,
            );
        }
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function rejectAllEmptyCriteria(int $index, string $name, array $criteria): void
    {
        if (array_filter($criteria) !== []) {
            return;
        }

        self::refuse(
            \sprintf('architecture.layers[%d]', $index),
            \sprintf(
                'architecture.layers[%d] ("%s"): must declare at least one of "patterns", "suffix", "attributes", "implements" or "extends".',
                $index,
                $name,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function ensureEntryIsAssociativeArray(int $index, mixed $entry): array
    {
        if (!\is_array($entry) || array_is_list($entry)) {
            self::refuse(
                \sprintf('architecture.layers[%d]', $index),
                \sprintf(
                    'architecture.layers[%d]: each entry must be a map with "name" and at least one criterion key, got %s.',
                    $index,
                    get_debug_type($entry),
                ),
            );
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function rejectUnknownKeys(int $index, array $entry): void
    {
        $unknown = array_diff(array_keys($entry), self::ALLOWED_ENTRY_KEYS);
        if ($unknown === []) {
            return;
        }

        $accepted = self::ALLOWED_ENTRY_KEYS;
        sort($accepted);

        throw ConfigurationRefusal::at(
            ConfigurationOrigin::of(ConfigurationSource::Resolved),
            RefusedPosition::closed(['architecture', 'layers', (string) $index], implode(', ', $unknown), $accepted),
            \sprintf(
                'architecture.layers[%d]: unknown key(s) %s. Allowed keys: %s.',
                $index,
                self::quoteList($unknown),
                self::quoteList(self::ALLOWED_ENTRY_KEYS),
            ),
        );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function extractValidName(int $index, array $entry): string
    {
        if (!\array_key_exists('name', $entry) || !\is_string($entry['name']) || $entry['name'] === '') {
            self::refuse(
                \sprintf('architecture.layers[%d].name', $index),
                \sprintf('architecture.layers[%d]: missing or empty "name" (must be a non-empty string).', $index),
            );
        }

        return $entry['name'];
    }

    /**
     * @param array<string, true> $seenNames
     */
    private static function rejectDuplicateName(int $index, string $name, array $seenNames): void
    {
        if (!isset($seenNames[$name])) {
            return;
        }

        self::refuse(
            \sprintf('architecture.layers[%d].name', $index),
            \sprintf(
                'architecture.layers[%d]: duplicate layer name "%s" — each layer must have a unique identifier.',
                $index,
                $name,
            ),
        );
    }

    /**
     * @param iterable<int|string> $items
     */
    private static function quoteList(iterable $items): string
    {
        $quoted = [];
        foreach ($items as $item) {
            $quoted[] = '"' . (string) $item . '"';
        }

        return implode(', ', $quoted);
    }

}
