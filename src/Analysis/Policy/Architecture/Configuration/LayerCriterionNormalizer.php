<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Architecture\Layer\CapturePattern;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerLifecycle;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\NamedType;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;

/**
 * Per-criterion semantic validator shared by {@see LayersValidator}
 * (positive criteria) and {@see ExcludeBlockValidator} (exclude criteria);
 * the form of a criterion is {@see CarriedValueForm}'s.
 *
 * Stateless: a single instance is reused across all entry indexes within
 * one {@code architecture.layers} validation pass. Each helper takes the
 * {@see SectionSpot} of the value plus the path-prefixing fields
 * ({@code $index}, {@code $layerName}, {@code $kind}) and either returns a
 * normalized list of strings (or {@see MatchMode}) or throws a
 * {@see ConfigurationRefusal} naming the layer that wrote the value.
 *
 * The semantic check (suffix shape, FQN shape, etc.) is supplied by the
 * caller-specific helper ({@see normalizeSuffixList},
 * {@see normalizeFqnList}, {@see normalizePatternList}).
 *
 * @qmx-ignore health.cohesion -- Stateless validation operations share no instance fields by design.
 */
final class LayerCriterionNormalizer
{
    /**
     * @param list<string> $path canonical path of the entry, its index last
     *
     * @throws ConfigurationRefusal naming the layer that wrote the entry
     */
    public static function ofLayerEntry(ResolvedValueInterface $entry, array $path): void
    {
        $index = (int) $path[\count($path) - 1];
        $spot = SectionSpot::node($path, $entry);
        $name = CarriedValueForm::layerName($index, $spot);

        self::judgeCriteria($index, $name, $spot);

        $exclude = $spot->child('exclude');
        if ($exclude->isWritten()) {
            $criteria = self::judgeCriteria($index, $name . '.exclude', $exclude);
            CarriedValueForm::rejectInvalidExcludeCapturePlacements(
                $index,
                $name,
                $criteria,
                TemplateLayerDefinition::containsCaptureVariable($name),
                $exclude,
            );
        }
    }

    /**
     * @return array{patterns: list<string>, suffix: list<string>, attributes: list<string>, member_attributes: list<string>, implements: list<string>, extends: list<string>}
     */
    private static function judgeCriteria(int $index, string $name, SectionSpot $entry): array
    {
        $normalizer = new LayerCriterionNormalizer();
        $criteria = [
            'patterns' => $normalizer->normalizePatternList($index, $name, $entry->child('patterns')),
            'suffix' => $normalizer->normalizeSuffixList($index, $name, $entry->child('suffix')),
            'attributes' => $normalizer->normalizeFqnList($index, $name, 'attributes', $entry->child('attributes')),
            'member_attributes' => $normalizer->normalizeFqnList($index, $name, 'member_attributes', $entry->child('member_attributes')),
            'implements' => $normalizer->normalizeFqnList($index, $name, 'implements', $entry->child('implements')),
            'extends' => $normalizer->normalizeFqnList($index, $name, 'extends', $entry->child('extends')),
        ];
        $normalizer->normalizeMatchMode($index, $name, $entry->child('match'));

        return $criteria;
    }

    /**
     * @return list<string>
     */
    public function normalizePatternList(int $index, string $layerName, SectionSpot $value): array
    {
        self::rejectSelectorSubtree($index, $layerName, $value);

        return self::normalizeStringList(
            $index,
            $layerName,
            'patterns',
            $value,
            static function (string $entry): ?string {
                if (str_starts_with($entry, 'subtree:') && \strlen($entry) > \strlen('subtree:')) {
                    return self::selectorSubtreeError(substr($entry, \strlen('subtree:')), $entry);
                }

                try {
                    CapturePattern::compile($entry);
                } catch (InvalidArgumentException $e) {
                    return 'has invalid Architecture pattern syntax: ' . $e->getMessage();
                }

                return null;
            },
        );
    }

    /**
     * @return list<string>
     */
    public function normalizeSuffixList(int $index, string $layerName, SectionSpot $value): array
    {
        return self::normalizeStringList(
            $index,
            $layerName,
            'suffix',
            $value,
            static function (string $entry): ?string {
                if (str_contains($entry, '\\')) {
                    return 'must be a short class-name suffix (no backslash); got "' . $entry . '". '
                        . 'Use "patterns" for FQN-shaped entries.';
                }

                return null;
            },
        );
    }

    /**
     * A leading separator is accepted and dropped: it is the only way to name
     * a class in the global namespace (`\Throwable`), and the run records
     * every name without one, so a name kept verbatim could never match.
     *
     * A class PHP declares is stored in the spelling its registry keeps, the
     * one the run records it by whatever case the source used: PHP class names
     * are case-insensitive, and `\exception` is `\Exception`. Kept verbatim,
     * it would be a type the run met that no class could ever match.
     *
     * @return list<string>
     */
    public function normalizeFqnList(int $index, string $layerName, string $kind, SectionSpot $value): array
    {
        return array_map(
            static fn(NamedType $type): string => $type->fqn,
            $this->normalizeNamedTypeList($index, $layerName, $kind, $value),
        );
    }

    /** @return list<NamedType> */
    public function normalizeNamedTypeList(int $index, string $layerName, string $kind, SectionSpot $value): array
    {
        $types = [];
        foreach (CarriedValueForm::criterionEntries($index, $layerName, $kind, $value) as $entryIndex => [$entry, $spot]) {
            $fqn = self::validateListEntry(
                $index,
                $layerName,
                $kind,
                $entryIndex,
                $entry,
                $spot,
                static function (string $entry) use ($kind): ?string {
                    if (!str_contains($entry, '\\')) {
                        return \sprintf(
                            'must be a fully-qualified class name (containing at least one namespace separator); got "%s". '
                            . 'Short names are not accepted in "%s"; a class in the global namespace is written with a '
                            . 'leading backslash, e.g. "\\%s".',
                            $entry,
                            $kind,
                            $entry,
                        );
                    }

                    if (ltrim($entry, '\\') === '') {
                        return \sprintf('must name a class; got "%s".', $entry);
                    }

                    return null;
                },
            );
            $types[] = new NamedType(PhpBuiltinClassRegistry::spelling(ltrim($fqn, '\\')), $spot->provenance());
        }

        return $types;
    }

    private static function rejectSelectorSubtree(int $index, string $layerName, SectionSpot $value): void
    {
        $written = $value->value();
        $root = self::selectorSubtreeRoot($written);
        if ($root !== null) {
            self::throwSelectorSubtreeRefusal($index, $layerName, $root, $value->child('subtree'));
        }

        if (!\is_array($written)) {
            return;
        }

        foreach (array_values($written) as $entryIndex => $entry) {
            $root = self::selectorSubtreeRoot($entry);
            if ($root !== null) {
                self::throwSelectorSubtreeRefusal($index, $layerName, $root, $value->child($entryIndex)->child('subtree'));
            }
        }
    }

    private static function selectorSubtreeRoot(mixed $entry): ?string
    {
        return \is_array($entry) && \count($entry) === 1 && \is_string($entry['subtree'] ?? null)
            ? $entry['subtree']
            : null;
    }

    private static function throwSelectorSubtreeRefusal(
        int $index,
        string $layerName,
        string $root,
        SectionSpot $spot,
    ): never {
        throw $spot->refusal(
            \sprintf(
                'architecture.layers[%d] ("%s"): "patterns" uses the selector grammar; %s',
                $index,
                $layerName,
                self::selectorSubtreeError($root, '{subtree: ' . $root . '}'),
            ),
            written: 'subtree',
        );
    }

    private static function selectorSubtreeError(string $root, string $written): string
    {
        return \sprintf('write "%s\\**" instead of "%s".', rtrim($root, '\\'), $written);
    }

    /**
     * Reads the optional {@code pending:} flag — the author's declaration that
     * the layer describes code not written yet, so
     * {@code architecture.unreachable-layer} must not report it.
     *
     * A template entry is rejected rather than ignored: it expands per observed
     * tuple, so its instances have matched something by construction and the
     * flag could never do anything. A template that produced nothing is
     * {@code architecture.empty-template}, a different channel the flag
     * deliberately does not reach.
     *
     * The configuration engine has already refused anything but a boolean.
     */
    public function normalizeLifecycle(int $index, string $layerName, SectionSpot $pending, bool $isTemplate): LayerLifecycle
    {
        if ($pending->value() !== true) {
            return LayerLifecycle::Active;
        }

        if ($isTemplate) {
            throw $this->entryError(
                $pending,
                $index,
                $layerName,
                '"pending" is not applicable to a template layer — a template expands only from tuples observed '
                . 'in the analysed code, so its instances always match something. A template that expanded to '
                . 'nothing is reported as architecture.empty-template.',
            );
        }

        return LayerLifecycle::Pending;
    }

    /**
     * The `architecture.layers[i] ("name"): ...` prefix both entry-level
     * normalizers report against, so the two cannot drift apart.
     */
    private function entryError(SectionSpot $spot, int $index, string $layerName, string $message): ConfigurationRefusal
    {
        return $spot->refusal(\sprintf('architecture.layers[%d] ("%s"): %s', $index, $layerName, $message));
    }

    public function normalizeMatchMode(int $index, string $layerName, SectionSpot $match): MatchMode
    {
        $value = $match->value();
        if ($value === null) {
            return MatchMode::Any;
        }

        if (\is_string($value)) {
            // Case-insensitive: `ANY`, `Any`, `any`, `aLL`, etc. all resolve.
            // `MatchMode::tryFrom()` itself is case-sensitive; normalize the
            // user input before delegation so the enum cases stay the single
            // source of truth for the canonical spelling.
            $candidate = MatchMode::tryFrom(strtolower($value));
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $allowed = implode(', ', array_map(
            static fn(MatchMode $mode): string => '"' . $mode->value . '"',
            MatchMode::cases(),
        ));

        throw $this->entryError($match, $index, $layerName, \sprintf(
            '"match" must be one of %s, got %s.',
            $allowed,
            \is_string($value) ? '"' . $value . '"' : get_debug_type($value),
        ));
    }

    /**
     * @param callable(string): ?string $semanticCheck Returns null on success
     *                                                 or an error fragment
     *                                                 appended to the message.
     *
     * @return list<string>
     */
    private static function normalizeStringList(
        int $index,
        string $layerName,
        string $kind,
        SectionSpot $value,
        callable $semanticCheck,
    ): array {
        $normalized = [];
        foreach (CarriedValueForm::criterionEntries($index, $layerName, $kind, $value) as $entryIndex => [$entry, $spot]) {
            $normalized[] = self::validateListEntry($index, $layerName, $kind, $entryIndex, $entry, $spot, $semanticCheck);
        }

        return $normalized;
    }

    /**
     * @param callable(string): ?string $semanticCheck
     */
    private static function validateListEntry(
        int $index,
        string $layerName,
        string $kind,
        int $entryIndex,
        string $entry,
        SectionSpot $spot,
        callable $semanticCheck,
    ): string {
        $semanticError = $semanticCheck($entry);
        if ($semanticError !== null) {
            throw $spot->refusal(
                \sprintf(
                    'architecture.layers[%d] ("%s"): "%s" entry at index %d %s',
                    $index,
                    $layerName,
                    $kind,
                    $entryIndex,
                    $semanticError,
                ),
                written: $entry,
            );
        }

        return $entry;
    }
}
