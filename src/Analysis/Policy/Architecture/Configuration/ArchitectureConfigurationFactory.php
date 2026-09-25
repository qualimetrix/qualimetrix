<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedOpaque;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerPolicy;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;

/**
 * Converts the resolved {@code architecture:} section into a typed
 * {@see ArchitectureFactoryResult} carrying the {@see ArchitectureConfiguration}
 * and any non-fatal warnings.
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
 *   coverage-gap: ignore
 * ```
 *
 * `layers` is an **ordered list**; the first layer whose patterns match a class
 * FQN owns that class. There is no specificity scoring and no collision
 * detection — order is the user's tool to express intent. See
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry} and ADR 0006.
 *
 * Responsibilities are delegated to focused validators living in
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Configuration}; this class is a
 * thin orchestrator that:
 *
 * 1. Reads the section the configuration engine resolved — keys recognised,
 *    forms judged and layers merged as {@see ArchitectureSection} declares.
 * 2. Runs the validators in a deterministic order
 *    ({@see LayersValidator} → {@see AllowValidator} →
 *    {@see ExactAllowCycleValidator} → {@see CoverageValidator} →
 *    {@see WildcardSelfAllowDetector}).
 * 3. Assembles the typed {@see ArchitectureConfiguration} and returns it
 *    together with the warning values.
 *
 * Every refusal names the configuration layer that wrote the refused value,
 * through the {@see SectionSpot} it was read from.
 *
 * **Warning delivery.** The factory does not depend on a PSR-3 logger. Architecture
 * reads its section from the resolved Configuration document when
 * RuntimeConfigurator invokes the policy after configuring the user logger. Warning values returned in {@see ArchitectureFactoryResult::$warnings}
 * are then logged immediately by RuntimeConfigurator.
 */
final class ArchitectureConfigurationFactory
{
    private readonly LayersValidator $layersValidator;

    private readonly AllowValidator $allowValidator;

    private readonly CoverageValidator $coverageValidator;

    private readonly ExactAllowCycleValidator $exactAllowCycleValidator;

    private readonly WildcardSelfAllowDetector $wildcardSelfAllowDetector;

    public function __construct(
        ?LayersValidator $layersValidator = null,
        ?AllowValidator $allowValidator = null,
        ?CoverageValidator $coverageValidator = null,
        ?ExactAllowCycleValidator $exactAllowCycleValidator = null,
        ?WildcardSelfAllowDetector $wildcardSelfAllowDetector = null,
    ) {
        $this->layersValidator = $layersValidator ?? new LayersValidator();
        $this->allowValidator = $allowValidator ?? new AllowValidator();
        $this->coverageValidator = $coverageValidator ?? new CoverageValidator();
        $this->exactAllowCycleValidator = $exactAllowCycleValidator ?? new ExactAllowCycleValidator();
        $this->wildcardSelfAllowDetector = $wildcardSelfAllowDetector ?? new WildcardSelfAllowDetector();
    }

    /**
     * @throws ConfigurationRefusal
     */
    public function fromResolved(ResolvedDocument $document): ArchitectureFactoryResult
    {
        $node = $document->get(ArchitectureSection::KEY);
        if ($node instanceof ResolvedOpaque) {
            throw new LogicException(\sprintf(
                'The "%s" section reached the configuration document undeclared; %s must be registered with the configuration pipeline.',
                ArchitectureSection::KEY,
                ArchitectureSection::class,
            ));
        }

        return $this->fromSection(SectionSpot::section(ArchitectureSection::KEY, $node));
    }

    private function fromSection(SectionSpot $section): ArchitectureFactoryResult
    {
        if (!$section->isWritten()) {
            return new ArchitectureFactoryResult(
                new ArchitectureConfiguration(
                    new LayerRegistry([]),
                    new LayerPolicy([]),
                    CoverageMode::Ignore,
                ),
            );
        }

        $layers = $section->child('layers');
        $entries = $this->layersValidator->validate($layers);
        $initialRegistry = self::buildInitialRegistry($entries, $layers);

        $warnings = [];
        $allow = $section->child('allow');
        $allowEntries = $this->allowValidator->validate(
            $allow,
            self::collectAllReferenceableNames($entries),
            $warnings,
        );
        $this->exactAllowCycleValidator->validate($allowEntries, $allow);

        $coverageGap = $section->child('coverage-gap');
        $coverage = $this->coverageValidator->validate($coverageGap);
        $this->coverageValidator->rejectModeWithNothingToJudge($coverage, $entries, $coverageGap, $layers);
        $maxExpandedLayers = self::validateMaxExpandedLayers($section->child('max_expanded_layers'));

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
     * Templates are deferred to {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage}
     * (runtime expansion); if the config has no templates the registry is
     * already the final one and {@see ArchitectureConfiguration::hasTemplates()}
     * returns false.
     *
     * @param list<LayerDefinition|TemplateLayerDefinition> $entries
     */
    private static function buildInitialRegistry(array $entries, SectionSpot $layers): LayerRegistry
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
            throw $layers->refusal(\sprintf('architecture.layers: %s', $e->getMessage()));
        }
    }

    /**
     * Returns the union of static layer names and template name templates.
     * Phase-2 allow lists can reference either a static layer name or a name
     * template (the latter resolves to a glob/captured selector once Step E
     * wires real binding flow). Exact-string allow-list entries
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
     * Validates the {@code max_expanded_layers} value: the engine has already
     * refused anything but an integer, so this refuses one below 1, showing
     * the default ceiling so the user knows what to put back.
     */
    private static function validateMaxExpandedLayers(SectionSpot $spot): int
    {
        $value = $spot->value() ?? ArchitectureConfiguration::DEFAULT_MAX_EXPANDED_LAYERS;
        if (!\is_int($value)) {
            throw new LogicException('The configuration engine admits only an integer as architecture.max_expanded_layers.');
        }

        if ($value < 1) {
            throw $spot->refusal(\sprintf(
                'architecture.max_expanded_layers: must be a positive integer (>= 1) — the cumulative ceiling on template-layer expansions. Got %d. Omit the key to use the default of %d, or set a higher integer if your config legitimately produces more layers.',
                $value,
                ArchitectureConfiguration::DEFAULT_MAX_EXPANDED_LAYERS,
            ));
        }

        return $value;
    }
}
