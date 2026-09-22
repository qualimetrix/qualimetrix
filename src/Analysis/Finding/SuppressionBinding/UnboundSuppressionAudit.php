<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

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
 * **Its Options service is the rule's own, and this service is lazy for that
 * reason.** The container builds the console command before the runtime
 * configuration has applied `rules.<name>.enabled` or `--rule-opt`, so an
 * eagerly constructed audit would capture an Options object that still reads
 * `enabled: true` after the configuration said otherwise. Declared lazy, the
 * audit is constructed at its first call, which is after the run.
 *
 * **Two gates keep this quiet where it cannot judge.** The caller asks the
 * project-wide one
 * ({@see \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage}) before
 * calling at all: on a run narrowed below the project's production autoload
 * roots, or on one whose manifest declares no readable production autoload,
 * there is nothing here to judge. Every surviving value is then asked about
 * individually ({@see ValueScopeJudgement}): `suppress_paths: [tests/Legacy]`
 * is correct configuration that `qmx check src/` cannot judge, and reporting
 * it there accused the author of the caller's choice of path.
 */
final readonly class UnboundSuppressionAudit
{
    /**
     * What one finding on these channels is about: the value, and — for a
     * per-rule entry — the rule and option it sits under.
     *
     * Without it every finding of a channel shared one baseline identity, and
     * an accepted entry bounded their *number* rather than naming them.
     * Accepting `src/Gone` then accepted `src/AlsoGone` in its place, silently,
     * which is the acceptance these channels exist to end.
     */
    private const string OCCURRENCE_KIND = 'unbound-suppression-value';

    public function __construct(
        private RuleOptionsInterface $options,
        private RuleExecutionInterface $ruleExecution,
        private RuleConfigurationInterface $ruleConfiguration,
    ) {}

    /**
     * One finding per configured value that bound to nothing this run holds.
     *
     * `$declaredNamespaces` is `null` when the run produced no namespace tree
     * at all. That is not "nothing bound": it is "the universe to judge
     * against was never built", and the namespace half stays silent rather
     * than reporting every configured namespace as unbound. The path half is
     * unaffected — its universe is the coverage, which always exists.
     *
     * The findings are passed through `publishable()` here rather than at the
     * seam: `--disable-rule`, `--only-rule`, the baseline and `--fail-on` must
     * see these findings the way they see every other one, and the seam has no
     * business knowing that a produced finding still has to survive selection.
     *
     * @param list<PathPattern> $suppressPaths global `suppress_paths`, `--suppress-path` included
     * @param list<NamespacePattern> $suppressNamespaces global `suppress_namespaces`, `--suppress-namespace` included
     * @param list<RelativePath> $analyzedFiles the run's own file universe
     * @param ?list<string> $declaredNamespaces every namespace the run declared, or `null` if it built no tree
     * @param ValueScopeJudgement $scope the run's shape, asked of every value before it is judged
     *
     * @return list<Finding>
     */
    public function findings(
        array $suppressPaths,
        array $suppressNamespaces,
        array $analyzedFiles,
        ?array $declaredNamespaces,
        ValueScopeJudgement $scope,
    ): array {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $findings = [];

        foreach ($this->unboundPaths($suppressPaths, $analyzedFiles, $scope) as $pattern) {
            $findings[] = self::pathFinding($pattern);
        }

        foreach ($this->unboundNamespaces($suppressNamespaces, $declaredNamespaces, $scope) as $pattern) {
            $findings[] = self::namespaceFinding($pattern);
        }

        foreach ($this->unboundLedgerEntries($analyzedFiles, $declaredNamespaces, $scope) as [$ruleName, $option, $pattern]) {
            $findings[] = self::ledgerFinding($ruleName, $option, $pattern);
        }

        return $this->ruleExecution->publishable($findings);
    }

    /**
     * Per-rule suppression entries that bound to nothing, read through
     * {@see ConfiguredSuppression} — the one reader
     * {@see \Qualimetrix\Analysis\Finding\FindingExclusionLedger} also uses
     * when it applies them, so "bound" here and "applied" there cannot mean two
     * different pattern sets.
     *
     * **All three options, including `suppress_namespace_channels`.** That one
     * was applied and not judged while each side enumerated the options for
     * itself, and a pattern under it sat in exactly the silence this channel
     * exists to end. A channel pattern is reported under the selector it was
     * written beneath, because that is the line an author has to find. Its
     * universe is the run's declared namespaces, the same one
     * `suppress_namespaces` is judged against: the ledger matches a channel
     * pattern with {@see NamespaceMatcher} against a namespace-level finding's
     * declared namespace.
     *
     * A rule name the registry does not know is not this channel's business:
     * an unknown `rules:` key is refused at configuration time, and an entry
     * under a rule that is switched off still binds or fails to bind on the
     * same evidence.
     *
     * @param list<RelativePath> $analyzedFiles
     * @param ?list<string> $declaredNamespaces
     *
     * @return list<array{string, string, SelectorDefinition}> rule name, option key, pattern
     */
    private function unboundLedgerEntries(
        array $analyzedFiles,
        ?array $declaredNamespaces,
        ValueScopeJudgement $scope,
    ): array {
        $entries = [];

        foreach ($this->ruleConfiguration->all() as $ruleName => $options) {
            if (!\is_array($options)) {
                continue;
            }

            foreach ($this->unboundPaths($this->ruleConfiguration->pathExclusions((string) $ruleName), $analyzedFiles, $scope) as $pattern) {
                $entries[] = [(string) $ruleName, ConfiguredSuppression::PATHS, $pattern->definition];
            }

            foreach ($this->unboundNamespaces($this->ruleConfiguration->namespaceExclusions((string) $ruleName), $declaredNamespaces, $scope) as $pattern) {
                $entries[] = [(string) $ruleName, ConfiguredSuppression::NAMESPACES, $pattern->definition];
            }

            foreach ($this->ruleConfiguration->namespaceChannelExclusions((string) $ruleName) as $selector => $patterns) {
                foreach ($this->unboundNamespaces($patterns, $declaredNamespaces, $scope) as $unbound) {
                    $entries[] = [
                        (string) $ruleName,
                        ConfiguredSuppression::NAMESPACE_CHANNELS . '.' . $selector,
                        $unbound->definition,
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * @param list<PathPattern> $patterns
     * @param list<RelativePath> $analyzedFiles
     *
     * @return list<PathPattern>
     */
    private function unboundPaths(array $patterns, array $analyzedFiles, ValueScopeJudgement $scope): array
    {
        $unbound = [];

        foreach ($patterns as $pattern) {
            if (!$scope->judgesPathValue($pattern)) {
                continue;
            }

            $bound = false;

            foreach ($analyzedFiles as $file) {
                if ($pattern->matches($file)) {
                    $bound = true;

                    break;
                }
            }

            if (!$bound) {
                $unbound[] = $pattern;
            }
        }

        return $unbound;
    }

    /**
     * @param list<NamespacePattern> $patterns
     * @param ?list<string> $declaredNamespaces
     *
     * @return list<NamespacePattern>
     */
    private function unboundNamespaces(array $patterns, ?array $declaredNamespaces, ValueScopeJudgement $scope): array
    {
        if ($declaredNamespaces === null) {
            return [];
        }

        $unbound = [];

        foreach ($patterns as $pattern) {
            if (!$scope->judgesNamespaceValue($pattern)) {
                continue;
            }

            $bound = false;

            foreach ($declaredNamespaces as $namespace) {
                if ($pattern->matches($namespace)) {
                    $bound = true;

                    break;
                }
            }

            if (!$bound) {
                $unbound[] = $pattern;
            }
        }

        return $unbound;
    }

    private static function pathFinding(PathPattern $pattern): Finding
    {
        return self::finding(
            UnboundSuppressionOptions::UNMATCHED_PATH,
            ['option' => ConfiguredSuppression::PATHS, 'pattern' => $pattern->definition->display()],
            \sprintf(
                'The suppress_paths pattern "%s" matched no file analysed by this run, so it suppressed nothing'
                . ' and could not have. If the code it was written for still exists under another spelling, its'
                . ' findings are being reported.',
                $pattern->definition->display(),
            ),
            \sprintf(
                'Check "%s" against the tree. %s Drop the entry if the code it names is gone, or correct its'
                . ' spelling.',
                $pattern->definition->display(),
                self::selectorMeaning($pattern->definition, '/', 'path'),
            ),
        );
    }

    private static function namespaceFinding(NamespacePattern $pattern): Finding
    {
        return self::finding(
            UnboundSuppressionOptions::UNMATCHED_NAMESPACE,
            ['option' => ConfiguredSuppression::NAMESPACES, 'pattern' => $pattern->definition->display()],
            \sprintf(
                'The suppress_namespaces pattern "%s" matched no namespace declared in this run, so it suppressed'
                . ' nothing and could not have. If the code it was written for still exists under another'
                . ' spelling, its findings are being reported.',
                $pattern->definition->display(),
            ),
            \sprintf(
                'Check "%s" against the code. %s Drop the entry if the namespace is gone, or correct its spelling.',
                $pattern->definition->display(),
                self::selectorMeaning($pattern->definition, '\\', 'namespace'),
            ),
        );
    }

    private static function selectorMeaning(
        SelectorDefinition $definition,
        string $separator,
        string $subject,
    ): string {
        return match ($definition->kind->value) {
            'exact' => \sprintf('The "exact" selector matches only the authored %s.', $subject),
            'subtree' => \sprintf(
                'The "subtree" selector also includes descendants across "%s" boundaries.',
                $separator,
            ),
            'regex' => 'The "regex" selector is a full-subject PCRE fragment.',
        };
    }

    private static function ledgerFinding(string $ruleName, string $option, SelectorDefinition $pattern): Finding
    {
        return self::finding(
            UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER,
            ['rule' => $ruleName, 'option' => $option, 'pattern' => $pattern->display()],
            \sprintf(
                'The %s pattern "%s" configured under rule "%s" matched nothing this run analysed, so it suppressed'
                . ' nothing and could not have.',
                $option,
                $pattern->display(),
                $ruleName,
            ),
            \sprintf(
                'Check "%s" under rules.%s.%s against the tree, and drop it if what it names is gone.',
                $pattern->display(),
                $ruleName,
                $option,
            ),
        );
    }

    /**
     * @param array<string, string> $occurrence what this finding is about, for its identity
     */
    private static function finding(string $channel, array $occurrence, string $message, string $recommendation): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: $channel,
            code: $channel,
            message: $message,
            severity: Severity::Warning,
            recommendation: $recommendation,
            occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, $occurrence),
        );
    }
}
