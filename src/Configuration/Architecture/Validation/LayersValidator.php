<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use InvalidArgumentException;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Core\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\MatchMode;
use Qualimetrix\Core\Architecture\Layer\MembershipSpec;
use Qualimetrix\Core\Architecture\Layer\TemplateLayerDefinition;

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
 * Produces a typed {@see LayerRegistry} preserving declaration order. Rejects
 * duplicate patterns across layers — under declaration-order matching the
 * second occurrence is unreachable and always a configuration mistake.
 *
 * Per-criterion shape validation is delegated to
 * {@see LayerCriterionNormalizer}; the {@code exclude:} sub-block is
 * delegated to {@see ExcludeBlockValidator}. Both helpers stay inside the
 * same namespace so the schema surface is co-located.
 *
 * All errors surface as {@see ConfigLoadException} with the logical path
 * {@code 'architecture'}.
 */
final class LayersValidator
{
    private const string CONFIG_PATH = 'architecture';

    /**
     * Keys still reserved for upcoming features. Step F opens
     * {@code exclude}; no further keys remain reserved at this time.
     */
    private const array RESERVED_FUTURE_KEYS = [];

    private const array ALLOWED_ENTRY_KEYS = [
        'name',
        'patterns',
        'suffix',
        'attributes',
        'implements',
        'extends',
        'match',
        'exclude',
    ];

    private readonly LayerCriterionNormalizer $normalizer;

    public function __construct()
    {
        $this->normalizer = new LayerCriterionNormalizer();
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
     * {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage}, not here.
     *
     * @return list<LayerDefinition|TemplateLayerDefinition>
     */
    public function validate(mixed $layersRaw): array
    {
        $entries = $this->buildLayerEntries($layersRaw);
        self::rejectDuplicatePatterns($entries);

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
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                'architecture.layers: must be an ordered list of layer entries, got ' . get_debug_type($layersRaw) . '.',
            );
        }

        if (!array_is_list($layersRaw)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
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

        if ($isTemplate) {
            return self::buildTemplateDefinition($index, $name, $criteria, $mode, $exclude);
        }

        return self::buildMembershipDefinition($index, $name, $criteria, $mode, $exclude);
    }

    /**
     * Constructs a {@see TemplateLayerDefinition} from a parsed entry whose
     * name contains capture variables. Catches the construction-time
     * invariant violations (empty name, variable in name not bound by any
     * capture-producing pattern, invalid capture grammar, undeclared
     * exclude variables) and rewraps them as {@see ConfigLoadException} so
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
            throw new ConfigLoadException(
                self::CONFIG_PATH,
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
    private static function buildMembershipDefinition(int $index, string $name, array $criteria, MatchMode $mode, ?ExcludeSpec $exclude): LayerDefinition
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
            );
        } catch (InvalidLayerDefinitionException | InvalidArgumentException $e) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
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

        throw new ConfigLoadException(
            self::CONFIG_PATH,
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
            throw new ConfigLoadException(
                self::CONFIG_PATH,
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

        $message = \sprintf(
            'architecture.layers[%d]: unknown key(s) %s. Allowed keys: %s.',
            $index,
            self::quoteList($unknown),
            self::quoteList(self::ALLOWED_ENTRY_KEYS),
        );

        $reservedSeen = array_values(array_intersect(self::RESERVED_FUTURE_KEYS, $unknown));
        if ($reservedSeen !== []) {
            $message .= \sprintf(
                ' Key(s) %s are reserved for an upcoming feature and not yet supported in this version.',
                self::quoteList($reservedSeen),
            );
        }

        throw new ConfigLoadException(self::CONFIG_PATH, $message);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function extractValidName(int $index, array $entry): string
    {
        if (!\array_key_exists('name', $entry) || !\is_string($entry['name']) || $entry['name'] === '') {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
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

        throw new ConfigLoadException(
            self::CONFIG_PATH,
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

    /**
     * Rejects duplicate patterns across different entries. Under
     * declaration-order semantics any class matching the duplicate would
     * always belong to the earlier entry — the second occurrence is
     * unreachable and is always a configuration mistake.
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
     * @param list<LayerDefinition|TemplateLayerDefinition> $entries
     */
    private static function rejectDuplicatePatterns(array $entries): void
    {
        $owners = [];
        foreach ($entries as $entry) {
            $entryName = $entry instanceof TemplateLayerDefinition ? $entry->nameTemplate() : $entry->name();
            $patterns = $entry instanceof TemplateLayerDefinition
                ? $entry->membership()->patterns
                : $entry->patterns();
            $seenInThisEntry = [];
            foreach ($patterns as $pattern) {
                $normalized = rtrim($pattern, '\\');
                if (isset($seenInThisEntry[$normalized])) {
                    continue;
                }
                $seenInThisEntry[$normalized] = true;

                if (isset($owners[$normalized]) && $owners[$normalized] !== $entryName) {
                    throw new ConfigLoadException(
                        self::CONFIG_PATH,
                        \sprintf(
                            'architecture.layers: pattern "%s" declared in both "%s" and "%s". Under declaration-order matching the second occurrence is unreachable; remove or refine one of them.',
                            $normalized,
                            $owners[$normalized],
                            $entryName,
                        ),
                    );
                }
                $owners[$normalized] = $entryName;
            }
        }
    }
}
