<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\GlobalValueWriters;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RulePublicationInterface;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The question {@see UnboundSuppressionRule} names but cannot ask, and the
 * findings that answer it.
 *
 * Split from the rule because the answer is only knowable where rules do not
 * run: at the reporting seam, where the configured suppression values and the
 * run's own universes — the files it analysed and the namespaces it declared —
 * are both in hand. It does not name the rule class in return: the channel
 * names live on {@see UnboundSuppressionOptions}, so the two classes do not
 * form a cycle.
 *
 * **Binding is measured against the universe, never against findings, and that
 * is the whole point of the channel.** A pattern is bound when the matcher the
 * suppression itself will use — {@see PathMatcher} for paths,
 * {@see NamespaceMatcher} for namespaces, the same two classes the global
 * filters and the per-rule ledger construct — accepts at least one analysed
 * file or one declared namespace. A suppressor that binds and removes nothing
 * is silent here and stays in `--format=suppressed`'s `neverMatched`, which
 * counts removals; a suppressor that binds to nothing is reported here and is
 * indistinguishable from the first in that list.
 *
 * The shared audit reads the ready invocation snapshot when called, so it
 * cannot retain an earlier invocation's enablement.
 *
 * **Two gates keep this quiet where it cannot judge.** The caller asks the
 * project-wide one
 * ({@see \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage}) before
 * calling at all: on a run narrowed below the project's production autoload
 * roots there is nothing here to judge. A project whose manifest declares no
 * readable production autoload is judged, its analysed paths taken as the
 * whole project — its path values only, since nothing locates a namespace
 * there. Every surviving value is then asked about
 * individually ({@see ValueScopeJudgement}): `suppress_paths: [tests/Legacy]`
 * is correct configuration that `qmx check src/` cannot judge, and reporting
 * it there accused the author of the caller's choice of path. A value skipped
 * that way is named by {@see unjudgedValues()} instead, so the skip reaches the
 * report rather than reading as a value judged and bound.
 *
 * **A second question is asked apart from the first**
 * ({@see ConfiguredValueJudge::installedOnly()}): an unbound value the project
 * can neither edit nor take away, because only installed files wrote it, is
 * not judged. It is named in {@see unjudgedValues()} and its sources in
 * {@see installedConfigurationSources()}. Writers are evidence about a value,
 * never a precondition for judging it: a value of unknown writers is judged.
 *
 * A file the run discovered but failed to analyse, or left out as generated,
 * is outside both universes, and a path value it may hold is not judged
 * either: it is named in
 * {@see unjudgedValues()}, never reported as unbound. A namespace value is
 * withheld on any failure, a path value only when it binds no analysed file
 * and an unanalysed entry (failed or generated-excluded) may hold what it names.
 *
  * @qmx-threshold coupling.instability 0.900001 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies.
 *
 * @phpstan-import-type FileUniverse from ConfiguredValueJudge
 * @phpstan-import-type ConfiguredValue from ConfiguredValueJudge
 */
final readonly class UnboundSuppressionAudit
{
    public function __construct(
        private RulePublicationInterface $rulePublication,
        private RuleConfigurationInterface $ruleConfiguration,
    ) {}

    /**
     * One finding per configured value that bound to nothing this run holds.
     *
     * `$declaredNamespaces` is `null` when the run produced no namespace tree
     * at all. That is not "nothing bound": it is "the universe to judge
     * against was never built", and the namespace half stays silent rather
     * than reporting every configured namespace as unbound — naming each such
     * value in {@see unjudgedValues()} instead. A file that failed analysis
     * leaves the tree without whatever it declares, so any failure silences
     * the namespace half the same way. The path half keeps its universe, the
     * coverage, which always exists; only a value an unanalysed entry may hold is
     * left unjudged (see {@see PathBinding::unanalysedMayHold()}).
     *
     * The findings are passed through `publishable()` here rather than at the
     * seam: `--disable-rule`, `--only-rule`, the baseline and `--fail-on` must
     * see these findings the way they see every other one, and the seam has no
     * business knowing that a produced finding still has to survive selection.
     *
     * @param list<PathPattern> $suppressPaths global `suppress_paths`, `--suppress-path` included
     * @param list<NamespacePattern> $suppressNamespaces global `suppress_namespaces`, `--suppress-namespace` included
     * @param FileUniverse $files the files the run analysed and the entries it left unanalysed
     * @param ?list<string> $declaredNamespaces every namespace the run declared, or `null` if it built no tree
     * @param ValueScopeJudgement $scope the run's shape, asked of every value before it is judged
     * @param GlobalValueWriters $writers who wrote each global value
     *
     * @return array{findings: list<Finding>, population: JudgedPopulation}
     */
    public function auditResult(
        array $suppressPaths,
        array $suppressNamespaces,
        array $files,
        ?array $declaredNamespaces,
        ValueScopeJudgement $scope,
        ChannelPublication $publication,
        GlobalValueWriters $writers = new GlobalValueWriters(),
    ): array {
        if (!$this->ruleConfiguration->resolvedOptions()->for(UnboundSuppressionRule::NAME)->isEnabled()) {
            return ['findings' => [], 'population' => JudgedPopulation::empty()];
        }

        $declarations = UnboundSuppressionRule::channelDeclarations();
        if (!self::hasSelectedChannel($publication, $declarations)) {
            return ['findings' => [], 'population' => JudgedPopulation::empty()];
        }
        $findings = [];
        $population = new UnboundSuppressionPopulation($publication->publishes(...), $declarations);
        $judge = new ConfiguredValueJudge($files, $declaredNamespaces, $scope);

        foreach ($this->configuredValues($suppressPaths, $suppressNamespaces, $writers) as $ordinal => $value) {
            if (!$publication->publishes(UnboundSuppressionRule::NAME, new FindingChannel($value['channel']), SymbolLevel::Project)) {
                continue;
            }
            $population->record($value, $ordinal, $judge->populationInputs($value));
            if ($judge->judges($value) && !$judge->binds($value)) {
                $findings[] = UnboundSuppressionFinding::forValue($value);
            }
        }

        unset($value);
        return ['findings' => $this->rulePublication->publishable($findings), 'population' => $population->freeze()];
    }

    /**
     * Every configured value {@see auditResult()} skipped without judging it,
     * under the channel that would have reported it — the other half of the
     * same enumeration, so the two cannot disagree about which values exist.
     *
     * Without this list a skipped value was indistinguishable from one judged
     * and bound: a `covered` run that never looked at `tests/Legacy` read the
     * same as one that found it. It does not depend on the rule being enabled,
     * for the reason a narrowed report names every channel of the family: it
     * says what this run's shape could not judge, not what would have fired.
     *
     * `option` is the key an author has to find: `suppress_paths` or
     * `suppress_namespaces` for a global value (a `--suppress-*` flag is
     * merged into those), `rules.<rule>.<option>` for a per-rule one.
     *
     * @param list<PathPattern> $suppressPaths
     * @param list<NamespacePattern> $suppressNamespaces
     * @param FileUniverse $files
     * @param ?list<string> $declaredNamespaces
     *
     * @return list<array{channel: string, option: string, pattern: string}>
     */
    public function unjudgedValues(
        array $suppressPaths,
        array $suppressNamespaces,
        array $files,
        ?array $declaredNamespaces,
        ValueScopeJudgement $scope,
        GlobalValueWriters $writers = new GlobalValueWriters(),
    ): array {
        $unjudged = [];
        $judge = new ConfiguredValueJudge($files, $declaredNamespaces, $scope);

        foreach ($this->configuredValues($suppressPaths, $suppressNamespaces, $writers) as $value) {
            if (!$judge->judges($value)) {
                $unjudged[] = self::published($value);
            }
        }

        return $unjudged;
    }

    /**
     * The sources, as `describe()` words them, of every value that only the
     * writers question kept from being judged: this run's shape reaches the
     * value, it binds nothing, and every file that wrote it is installed.
     *
     * A value another question already withholds names no source here, so a
     * source is reported only when it changed the answer.
     *
     * @param list<PathPattern> $suppressPaths
     * @param list<NamespacePattern> $suppressNamespaces
     * @param FileUniverse $files
     * @param ?list<string> $declaredNamespaces
     *
     * @return list<string>
     */
    public function installedConfigurationSources(
        array $suppressPaths,
        array $suppressNamespaces,
        array $files,
        ?array $declaredNamespaces,
        ValueScopeJudgement $scope,
        GlobalValueWriters $writers,
    ): array {
        $sources = [];
        $judge = new ConfiguredValueJudge($files, $declaredNamespaces, $scope);
        foreach ($this->configuredValues($suppressPaths, $suppressNamespaces, $writers) as $value) {
            if ($value['writers'] !== null && $judge->reaches($value) && $judge->installedOnly($value)) {
                $sources = [...$sources, ...array_map(static fn($origin): string => $origin->describe(), $value['writers']->origins)];
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * The values of {@see unjudgedValues()} that removed code alone kept from
     * being judged: each would be judged had that code declared nothing.
     *
     * A value withheld for any other reason as well — an unanalysed PSR-4
     * root, no production autoload, no complete namespace tree, a regex with
     * no literal anchor — is not named, because narrowing the removal would
     * not judge it. Nor is one the analysed tree already binds, or one only
     * installed files wrote ({@see ConfiguredValueJudge::withheldOnlyForRemovedCode()}).
     *
     * @param list<PathPattern> $suppressPaths
     * @param list<NamespacePattern> $suppressNamespaces
     * @param FileUniverse $files
     * @param ?list<string> $declaredNamespaces
     *
     * @return list<array{channel: string, option: string, pattern: string}>
     */
    public function withheldForRemovedCode(
        array $suppressPaths,
        array $suppressNamespaces,
        array $files,
        ?array $declaredNamespaces,
        ValueScopeJudgement $scope,
        GlobalValueWriters $writers = new GlobalValueWriters(),
    ): array {
        $withheld = [];
        $judge = new ConfiguredValueJudge($files, $declaredNamespaces, $scope);
        foreach ($this->configuredValues($suppressPaths, $suppressNamespaces, $writers) as $value) {
            if ($judge->withheldOnlyForRemovedCode($value)) {
                $withheld[] = self::published($value);
            }
        }

        return $withheld;
    }

    /**
     * @param ConfiguredValue $value
     *
     * @return array{channel: string, option: string, pattern: string}
     */
    private static function published(array $value): array
    {
        return [
            'channel' => $value['channel'],
            'option' => $value['rule'] === null ? $value['option'] : \sprintf('rules.%s.%s', $value['rule'], $value['option']),
            'pattern' => $value['pattern']->definition->display(),
        ];
    }

    /**
     * @param list<PathPattern> $suppressPaths
     * @param list<NamespacePattern> $suppressNamespaces
     * @param FileUniverse $files
     * @param ?list<string> $declaredNamespaces
     *
     * @return list<string>
     */
    public function judgedChannels(array $suppressPaths, array $suppressNamespaces, array $files, ?array $declaredNamespaces, ValueScopeJudgement $scope, GlobalValueWriters $writers = new GlobalValueWriters()): array
    {
        $channels = [];
        $judge = new ConfiguredValueJudge($files, $declaredNamespaces, $scope);
        foreach ($this->configuredValues($suppressPaths, $suppressNamespaces, $writers) as $value) {
            if ($judge->judges($value)) {
                $channels[$value['channel']] = true;
            }
        }

        return array_keys($channels);
    }

    /**
     * Every configured value, global and per-rule, with the channel that
     * reports it.
     *
     * Per-rule entries come from the current typed snapshot's suppression
     * values, so the audit cannot re-parse a different pattern set.
     *
     * **All three per-rule options, including `suppress_namespace_channels`.**
     * That one was applied and not judged while each side enumerated the
     * options for itself, and a pattern under it sat in exactly the silence
     * this channel exists to end. A channel pattern is reported under the
     * selector it was written beneath, because that is the line an author has
     * to find. Its universe is the run's declared namespaces, the same one
     * `suppress_namespaces` is judged against: the ledger matches a channel
     * pattern with {@see NamespaceMatcher} against a namespace-level finding's
     * declared namespace.
     *
     * A rule name the registry does not know is not this channel's business:
     * an unknown `rules:` key is refused at configuration time, and an entry
     * under a rule that is switched off still binds or fails to bind on the
     * same evidence.
     *
     * @param list<PathPattern> $suppressPaths
     * @param list<NamespacePattern> $suppressNamespaces
     *
     * @return list<ConfiguredValue>
     */
    private function configuredValues(array $suppressPaths, array $suppressNamespaces, GlobalValueWriters $writers): array
    {
        $values = [];

        foreach ($suppressPaths as $pattern) {
            $values[] = ['channel' => UnboundSuppressionOptions::UNMATCHED_PATH, 'rule' => null, 'option' => ConfiguredSuppression::PATHS, 'pattern' => $pattern, 'writers' => $writers->for($pattern)];
        }

        foreach ($suppressNamespaces as $pattern) {
            $values[] = ['channel' => UnboundSuppressionOptions::UNMATCHED_NAMESPACE, 'rule' => null, 'option' => ConfiguredSuppression::NAMESPACES, 'pattern' => $pattern, 'writers' => $writers->for($pattern)];
        }

        $snapshot = $this->ruleConfiguration->resolvedOptions();
        foreach ($snapshot->all() as $rule => $options) {
            $suppression = $snapshot->suppressionFor($rule);
            $entries = [
                ...array_map(static fn(PathPattern $p): array => [ConfiguredSuppression::PATHS, $p, $suppression->pathWriters], $suppression->paths),
                ...array_map(static fn(NamespacePattern $p): array => [ConfiguredSuppression::NAMESPACES, $p, $suppression->namespaceWriters], $suppression->namespaces),
            ];

            foreach ($suppression->namespaceChannels as $selector => $patterns) {
                foreach ($patterns as $pattern) {
                    $entries[] = [ConfiguredSuppression::NAMESPACE_CHANNELS . '.' . $selector, $pattern, $suppression->namespaceChannelWriters[$selector] ?? null];
                }
            }

            foreach ($entries as [$option, $pattern, $writtenBy]) {
                $values[] = ['channel' => UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER, 'rule' => $rule, 'option' => $option, 'pattern' => $pattern, 'writers' => $writtenBy];
            }
        }

        return $values;
    }

    /** @param array<string, ChannelDeclaration> $declarations */
    private static function hasSelectedChannel(ChannelPublication $publication, array $declarations): bool
    {
        return array_any(array_keys($declarations), static fn(string $code): bool => $publication->publishes(UnboundSuppressionRule::NAME, new FindingChannel($code), SymbolLevel::Project));
    }
}
