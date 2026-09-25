<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerLifecycle;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;

/**
 * Parses and validates the {@code architecture.layers} sub-tree.
 *
 * Accepts the long-form ordered list with five criterion kinds plus the
 * optional {@code exclude:} block:
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
 * The configuration engine has already recognised every key of an entry and
 * judged the form of its name, `match` and `pending`; what is left here is
 * what a value means. Every refusal names the layer that wrote the entry,
 * through its {@see SectionSpot}.
 */
final class LayersValidator
{
    private readonly LayerCriterionNormalizer $normalizer;

    public function __construct()
    {
        $this->normalizer = new LayerCriterionNormalizer();
    }

    /**
     * Parses the resolved {@code layers} list into the declaration-order list of
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
    public function validate(SectionSpot $layers): array
    {
        $entries = $this->buildLayerEntries($layers);
        DuplicatePatternRejector::reject($entries, $layers);

        return $entries;
    }

    /**
     * @return list<LayerDefinition|TemplateLayerDefinition>
     */
    private function buildLayerEntries(SectionSpot $layers): array
    {
        $entries = [];
        $seenNames = [];
        foreach (array_keys((array) $layers->value()) as $index) {
            $entries[] = $this->buildSingleLayerEntry((int) $index, $layers->child($index), $seenNames);
        }

        return $entries;
    }

    /**
     * @param array<string, true> $seenNames
     *
     * @param-out array<string, true> $seenNames
     */
    private function buildSingleLayerEntry(int $index, SectionSpot $entry, array &$seenNames): LayerDefinition|TemplateLayerDefinition
    {
        $name = self::extractValidName($index, $entry);
        self::rejectDuplicateName($index, $name, $entry, $seenNames);
        $seenNames[$name] = true;

        $criteria = $this->normalizeCriteria($index, $name, $entry);
        $mode = $this->normalizer->normalizeMatchMode($index, $name, $entry->child('match'));
        $isTemplate = TemplateLayerDefinition::containsCaptureVariable($name);
        self::rejectCapturesInStaticPatterns($index, $name, $criteria['patterns'], $isTemplate, $entry->child('patterns'));
        $exclude = ExcludeBlockValidator::parse($index, $name, $entry->child('exclude'), $isTemplate, $this->normalizer);
        $lifecycle = $this->normalizer->normalizeLifecycle($index, $name, $entry->child('pending'), $isTemplate);

        if ($isTemplate) {
            return self::buildTemplateDefinition($index, $name, $criteria, $mode, $exclude, $entry);
        }

        return self::buildMembershipDefinition($index, $name, $criteria, $mode, $exclude, $lifecycle, $entry);
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
    private static function buildTemplateDefinition(int $index, string $nameTemplate, array $criteria, MatchMode $mode, ?ExcludeSpec $exclude, SectionSpot $entry): TemplateLayerDefinition
    {
        self::rejectAllEmptyCriteria($index, $nameTemplate, $criteria, $entry);
        self::rejectUnboundNonPatternCriteria($index, $nameTemplate, $criteria, $mode, $entry);

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
            throw $entry->refusal(\sprintf('architecture.layers[%d] ("%s"): %s', $index, $nameTemplate, $e->getMessage()));
        }
    }

    /**
     * Collects the five criterion lists from a single layer entry.
     *
     * @return array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>}
     */
    private function normalizeCriteria(int $index, string $name, SectionSpot $entry): array
    {
        return [
            'patterns' => $this->normalizer->normalizePatternList($index, $name, $entry->child('patterns')),
            'suffix' => $this->normalizer->normalizeSuffixList($index, $name, $entry->child('suffix')),
            'attributes' => $this->normalizer->normalizeFqnList($index, $name, 'attributes', $entry->child('attributes')),
            'implements' => $this->normalizer->normalizeFqnList($index, $name, 'implements', $entry->child('implements')),
            'extends' => $this->normalizer->normalizeFqnList($index, $name, 'extends', $entry->child('extends')),
        ];
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function buildMembershipDefinition(int $index, string $name, array $criteria, MatchMode $mode, ?ExcludeSpec $exclude, LayerLifecycle $lifecycle, SectionSpot $entry): LayerDefinition
    {
        self::rejectAllEmptyCriteria($index, $name, $criteria, $entry);

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
            throw $entry->refusal(\sprintf('architecture.layers[%d] ("%s"): %s', $index, $name, $e->getMessage()));
        }
    }

    /**
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function rejectAllEmptyCriteria(int $index, string $name, array $criteria, SectionSpot $entry): void
    {
        if (array_filter($criteria) !== []) {
            return;
        }

        throw $entry->refusal(
            \sprintf(
                'architecture.layers[%d] ("%s"): must declare at least one of "patterns", "suffix", "attributes", "implements" or "extends".',
                $index,
                $name,
            ),
        );
    }

    /**
     * Refuses a template that declares a non-pattern criterion it cannot bind
     * to the instances it produces.
     *
     * Only `patterns` carry capture variables, so `suffix`, `attributes`,
     * `implements` and `extends` are copied into every expanded layer verbatim.
     * Under `match: all` that is harmless — the criterion narrows each instance
     * within the scope its own substituted pattern already fixes. Under
     * `match: any` it is OR'd with that pattern instead, so every instance
     * carries the same global net: one clause reading "or anything named
     * *Repository" makes `domain-Order`, `domain-Billing` and every sibling
     * claim every Repository in the codebase, and the first instance in
     * expansion order — which is binding-value alphabetical, not anything the
     * author wrote — wins it. The class then belongs to an arbitrary module,
     * is judged against that module's allow-list, and appears in no coverage
     * report at all.
     *
     * Refused rather than silently narrowed: scoping the criterion to the
     * instance's own pattern would make it a subset of that pattern and so
     * inert, which trades a wrong answer for one that does nothing while still
     * looking like it does something. `match: all` expresses the narrowing the
     * author almost certainly meant, and a static layer expresses the global
     * net if that is really what was wanted.
     *
     * @param array{patterns: list<string>, suffix: list<string>, attributes: list<string>, implements: list<string>, extends: list<string>} $criteria
     */
    private static function rejectUnboundNonPatternCriteria(int $index, string $nameTemplate, array $criteria, MatchMode $mode, SectionSpot $entry): void
    {
        if ($mode === MatchMode::All) {
            return;
        }

        $declared = [];
        foreach (['suffix', 'attributes', 'implements', 'extends'] as $kind) {
            if ($criteria[$kind] !== []) {
                $declared[] = $kind;
            }
        }

        if ($declared === []) {
            return;
        }

        throw $entry->refusal(
            \sprintf(
                'architecture.layers[%d] ("%s"): %s cannot be combined with "match: any" on a template layer. '
                . 'Only "patterns" carry the capture variables, so %s would be copied into every expanded layer '
                . 'unchanged and, OR-ed with the substituted pattern, would make every instance claim the same '
                . 'classes project-wide — the instance that wins one is then decided by binding-value order rather '
                . 'than by the declaration. Add "match: all" so the criterion narrows each instance, or declare a '
                . 'static layer if the criterion really is meant to apply project-wide.',
                $index,
                $nameTemplate,
                self::quoteList($declared),
                \count($declared) === 1 ? 'it' : 'they',
            ),
        );
    }

    /** @param list<string> $patterns */
    private static function rejectCapturesInStaticPatterns(int $index, string $name, array $patterns, bool $isTemplate, SectionSpot $spot): void
    {
        if ($isTemplate) {
            return;
        }

        foreach ($patterns as $patternIndex => $pattern) {
            if (!TemplateLayerDefinition::containsCaptureVariable($pattern)) {
                continue;
            }

            throw (\is_array($spot->value()) ? $spot->child($patternIndex) : $spot)->refusal(
                \sprintf(
                    'architecture.layers[%d] ("%s"): pattern "%s" contains a capture variable, but static layers have no binding target. Add the variable to the layer name or remove the capture.',
                    $index,
                    $name,
                    $pattern,
                ),
            );
        }
    }

    private static function extractValidName(int $index, SectionSpot $entry): string
    {
        $name = $entry->child('name');
        $value = $name->value();
        if (!\is_string($value) || $value === '') {
            throw $name->refusal(\sprintf('architecture.layers[%d]: missing or empty "name" (must be a non-empty string).', $index));
        }

        return $value;
    }

    /**
     * @param array<string, true> $seenNames
     */
    private static function rejectDuplicateName(int $index, string $name, SectionSpot $entry, array $seenNames): void
    {
        if (!isset($seenNames[$name])) {
            return;
        }

        throw $entry->child('name')->refusal(
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
