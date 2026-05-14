<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture;

use InvalidArgumentException;
use Qualimetrix\Configuration\Architecture\Validation\AllowValidator;
use Qualimetrix\Configuration\Architecture\Validation\CoverageValidator;
use Qualimetrix\Configuration\Architecture\Validation\LayersValidator;
use Qualimetrix\Configuration\Architecture\Validation\MutualAllowDetector;
use Qualimetrix\Configuration\Architecture\Validation\WildcardSelfAllowDetector;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerPolicy;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;
use Qualimetrix\Core\Architecture\Layer\TemplateLayerDefinition;

/**
 * Converts the raw YAML map under the {@code architecture:} key into a typed
 * {@see ArchitectureFactoryResult} carrying the resolved
 * {@see ArchitectureConfiguration} and any deferred warnings.
 *
 * Schema (declaration-order matching, first match wins):
 *
 * ```yaml
 * architecture:
 *   layers:
 *     - name: controller
 *       patterns: ['App\Controller\**']
 *     - name: repository
 *       patterns: ['App\Repository\**']
 *   allow:
 *     controller: [repository]
 *   coverage: ignore
 * ```
 *
 * `layers` is an **ordered list**; the first layer whose patterns match a class
 * FQN owns that class. There is no specificity scoring and no collision
 * detection — order is the user's tool to express intent. See
 * {@see \Qualimetrix\Core\Architecture\Layer\LayerRegistry} and ADR 0006.
 *
 * Responsibilities are delegated to focused validators living in
 * {@see \Qualimetrix\Configuration\Architecture\Validation}; this class is a
 * thin orchestrator that:
 *
 * 1. Validates the top-level shape (`layers`, `allow`, `coverage` keys only).
 * 2. Runs the validators in a deterministic order
 *    ({@see LayersValidator} → {@see AllowValidator} → {@see CoverageValidator}
 *    → {@see MutualAllowDetector} → {@see WildcardSelfAllowDetector}).
 * 3. Assembles the typed {@see ArchitectureConfiguration} and returns it
 *    together with the deferred-warning list.
 *
 * All structural errors surface as {@see ConfigLoadException} with the
 * logical path {@code 'architecture'}.
 *
 * **Warning delivery.** The factory does NOT depend on a PSR-3 logger. Warnings
 * are appended to a local list and returned inside
 * {@see ArchitectureFactoryResult::$warnings}. The factory runs during
 * configuration resolution, which happens before the user-facing logger is
 * configured by
 * {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator::configureLogger()};
 * deferring the warnings ensures they survive until the logger is ready.
 * {@see \Qualimetrix\Configuration\Pipeline\ConfigurationPipeline} collects the
 * list into {@see \Qualimetrix\Configuration\Pipeline\ResolvedConfiguration::$deferredWarnings},
 * and {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator} drains it
 * to the configured logger after the holder is populated.
 */
final class ArchitectureConfigurationFactory
{
    private const string CONFIG_PATH = 'architecture';

    private const array ALLOWED_TOP_LEVEL_KEYS = ['layers', 'allow', 'coverage', 'max_expanded_layers'];

    private readonly LayersValidator $layersValidator;

    private readonly AllowValidator $allowValidator;

    private readonly CoverageValidator $coverageValidator;

    private readonly MutualAllowDetector $mutualAllowDetector;

    private readonly WildcardSelfAllowDetector $wildcardSelfAllowDetector;

    public function __construct(
        ?LayersValidator $layersValidator = null,
        ?AllowValidator $allowValidator = null,
        ?CoverageValidator $coverageValidator = null,
        ?MutualAllowDetector $mutualAllowDetector = null,
        ?WildcardSelfAllowDetector $wildcardSelfAllowDetector = null,
    ) {
        $this->layersValidator = $layersValidator ?? new LayersValidator();
        $this->allowValidator = $allowValidator ?? new AllowValidator();
        $this->coverageValidator = $coverageValidator ?? new CoverageValidator();
        $this->mutualAllowDetector = $mutualAllowDetector ?? new MutualAllowDetector();
        $this->wildcardSelfAllowDetector = $wildcardSelfAllowDetector ?? new WildcardSelfAllowDetector();
    }

    /**
     * Converts the merged YAML map under {@code architecture:} to a typed VO
     * paired with a list of deferred warnings.
     *
     * Callers can pass {@code $merged['architecture'] ?? []} directly; both
     * associative and (degenerate) sequential arrays are accepted at the type
     * level and rejected by structural validation below.
     *
     * Unknown top-level keys (typos like {@code layres:} or unrecognized fields
     * like {@code imports:}) trigger a {@see ConfigLoadException} so that user
     * mistakes never silently disable architecture rules.
     *
     * @param array<string, mixed>|array<int, mixed> $raw
     */
    public function fromArray(array $raw): ArchitectureFactoryResult
    {
        if ($raw === []) {
            return new ArchitectureFactoryResult(
                new ArchitectureConfiguration(
                    new LayerRegistry([]),
                    new LayerPolicy([]),
                    CoverageMode::Ignore,
                ),
            );
        }

        $this->validateTopLevelStructure($raw);

        $entries = $this->layersValidator->validate($raw['layers'] ?? []);
        $initialRegistry = self::buildInitialRegistry($entries);

        $warnings = [];
        $allowEntries = $this->allowValidator->validate(
            $raw['allow'] ?? [],
            self::collectAllReferenceableNames($entries),
            $warnings,
        );

        $coverage = $this->coverageValidator->validate($raw['coverage'] ?? null);
        $maxExpandedLayers = self::validateMaxExpandedLayers(
            $raw['max_expanded_layers'] ?? ArchitectureConfiguration::DEFAULT_MAX_EXPANDED_LAYERS,
        );

        $this->mutualAllowDetector->detect($allowEntries, $warnings);
        $this->wildcardSelfAllowDetector->detect($allowEntries, $warnings);

        return new ArchitectureFactoryResult(
            new ArchitectureConfiguration(
                registry: $initialRegistry,
                policy: new LayerPolicy($allowEntries),
                coverage: $coverage,
                entries: $entries,
                maxExpandedLayers: $maxExpandedLayers,
            ),
            $warnings,
        );
    }

    /**
     * Builds the registry seeded with the static-only subset of entries.
     * Templates are deferred to {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage}
     * (runtime expansion); if the config has no templates the registry is
     * already the final one and {@see ArchitectureConfiguration::hasTemplates()}
     * returns false.
     *
     * @param list<LayerDefinition|TemplateLayerDefinition> $entries
     */
    private static function buildInitialRegistry(array $entries): LayerRegistry
    {
        $staticLayers = [];
        foreach ($entries as $entry) {
            if ($entry instanceof LayerDefinition) {
                $staticLayers[] = $entry;
            }
        }

        try {
            return new LayerRegistry($staticLayers);
        } catch (InvalidArgumentException $e) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.layers: %s', $e->getMessage()),
                $e,
            );
        }
    }

    /**
     * Returns the union of static layer names and template name templates.
     * Phase-2 allow lists can reference either a static layer name or a name
     * template (the latter resolves to a glob/captured selector once Step E
     * wires real binding flow). For Step D, exact-string allow-list entries
     * referencing a template name template skip cross-validation since the
     * template's concrete instances are not known at config-load time —
     * matching is handled at expansion-time by the policy resolver. Here we
     * surface the union so existing AllowValidator semantics (which check
     * exact selectors against known names) continue to recognise both kinds.
     *
     * @param list<LayerDefinition|TemplateLayerDefinition> $entries
     *
     * @return list<string>
     */
    private static function collectAllReferenceableNames(array $entries): array
    {
        $names = [];
        foreach ($entries as $entry) {
            $names[] = $entry instanceof TemplateLayerDefinition ? $entry->nameTemplate() : $entry->name();
        }

        return $names;
    }

    /**
     * Validates the {@code max_expanded_layers} value. Accepts a positive
     * integer; rejects anything else with a config-load error pointing at
     * the precise key.
     */
    private static function validateMaxExpandedLayers(mixed $value): int
    {
        if (!\is_int($value) || $value < 1) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    'architecture.max_expanded_layers: must be a positive integer, got %s.',
                    \is_int($value) ? (string) $value : get_debug_type($value),
                ),
            );
        }

        return $value;
    }

    /**
     * Validates that {@code $raw} is an associative map whose keys are exactly
     * the well-known top-level architecture keys (`layers`, `allow`, `coverage`).
     *
     * @param array<string, mixed>|array<int, mixed> $raw
     */
    private function validateTopLevelStructure(array $raw): void
    {
        if (array_is_list($raw)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                'architecture: must be a map with keys "layers", "allow", "coverage"; a sequential list is not allowed.',
            );
        }

        $unknown = [];
        foreach (array_keys($raw) as $key) {
            if (!\is_string($key) || !\in_array($key, self::ALLOWED_TOP_LEVEL_KEYS, true)) {
                $unknown[] = (string) $key;
            }
        }

        if ($unknown === []) {
            return;
        }

        throw new ConfigLoadException(
            self::CONFIG_PATH,
            \sprintf(
                'architecture: unknown %s %s. Allowed keys: %s.',
                \count($unknown) === 1 ? 'key' : 'keys',
                implode(', ', array_map(static fn(string $k): string => '"' . $k . '"', $unknown)),
                implode(', ', array_map(static fn(string $k): string => '"' . $k . '"', self::ALLOWED_TOP_LEVEL_KEYS)),
            ),
        );
    }
}
