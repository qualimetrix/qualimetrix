<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Reports a `coupling.frameworkNamespaces` prefix that no name in the run
 * falls under.
 *
 * The prefix was written to move classes out of `coupling.cbo-app` and into
 * `coupling.ce-framework` ({@see CouplingCollector}). One that binds nothing
 * moves neither, so every application-scope coupling verdict is drawn from a
 * wider set than the author asked for — and nothing else in the run says so.
 * The two states are not merely quiet, they are byte-identical: a missed
 * prefix produces the same report as no prefix at all, which is what makes
 * this a silent acceptance rather than a subtle one.
 *
 * **A rule of its own rather than a second channel on {@see CboRule}.** That
 * was the first shape tried and the container refuses it: a magnitude producer
 * must give every channel a `WorseDirection`
 * ({@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}),
 * and this finding judges no measured value — it reports that a configured
 * name classified nothing. The subjects differ too: `coupling.cbo` is about
 * one class's coupling, this is about the run's configuration.
 *
 * **Not a {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface}.**
 * A validator's channel fails the run regardless of `fail_on` and can never be
 * accepted by a baseline. A prefix that matches nothing is not that: the
 * configuration parses and the run is honest, it just answers a wider question
 * than asked. That is ordinary debt — a shared `qmx.yaml` may legitimately
 * name a framework one of its repositories does not use — so the finding goes
 * through a rule and answers to `fail_on`, `--disable-rule` and the baseline
 * like any other.
 *
 * **Statelessness:** nothing this rule computes survives an `analyze()` call.
 * The prefixes it reads are the run's configuration, written once by the
 * console before analysis begins.
 */
final class UnmatchedFrameworkNamespaceRule extends AbstractRule
{
    public const string NAME = 'coupling.unmatched-framework-namespace';
    public const string DOCS_PAGE = 'rules/coupling.md';

    /** Editing one line of `qmx.yaml`, plus reading what the code really imports. */
    public const int REMEDIATION_MINUTES = 10;

    public const ChannelShape SHAPE = ChannelShape::Occurrence;

    /**
     * The configured prefixes are injected by {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass::resolveExtraDependencies()}.
     * Rules cannot use plain constructor autowiring (Critical Rule 7), so the
     * compiler-pass injection is the supported flow. It binds the same
     * instance the console configures before the run and the same one
     * {@see CouplingCollector} classifies with, which is what makes the
     * question this rule asks the one the metrics actually answered.
     */
    public function __construct(
        RuleOptionsInterface $options,
        private readonly CouplingAnalysis $coupling,
    ) {
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Reports a coupling.frameworkNamespaces prefix that classified nothing';
    }

    /**
     * @return class-string<UnmatchedFrameworkNamespaceOptions>
     */
    public static function getOptionsClass(): string
    {
        return UnmatchedFrameworkNamespaceOptions::class;
    }

    /**
     * Occurrence, and reported on the project: the finding counts nothing and
     * belongs to no declaration — the prefix is a fact about the run's
     * configuration, the way `architecture.unreachable-layer` is
     * ({@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\DeclaredLayerReachability}).
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::occurrence(SymbolLevel::Project),
        ];
    }

    /**
     * One finding per declared prefix that bound nothing.
     *
     * **The universe is the names the collector actually classifies**, not the
     * analysed declarations: `CouplingCollector::isFrameworkSymbol()` is asked
     * about both ends of every edge — dependency targets, which is where
     * external framework classes live, and dependency sources. A prefix
     * matching either end changes `coupling.cbo-app`, so a prefix matching
     * neither is exactly the prefix that changed nothing.
     *
     * **Three preconditions. The first is the scope of the run itself**
     * ({@see \Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext::$coversProjectScope}):
     * "this prefix bound nothing" is a fact about the pair (configuration, run
     * scope), never about the configuration alone. Measured on this tree:
     * `qmx check src/Analysis/Evidence/Cohesion/` under the project's own
     * `qmx.yaml` leaves three of its four prefixes unbound, and the author's
     * configuration is faultless — the framework code is outside the slice.
     * On a narrowed run the honest answer is that there is nothing here to
     * check, so the channel is silent; the cost is that a genuinely stale
     * prefix waits for a whole-project run to be reported, which is the run
     * that can tell the two apart.
     *
     * **The other two keep the channel quiet on a degenerate run.** Without a
     * dependency graph there is no universe to ask about at all. And a run
     * whose graph carries no dependency edge — one self-contained file,
     * checked with the project's own `qmx.yaml` — gave the collector nothing
     * to classify, so every prefix is inert there for a reason that has
     * nothing to do with its spelling. That is the same predicate
     * `architecture.unmatched-exclude` uses when it declines to report a layer
     * whose own criteria matched nothing.
     *
     * The graph preconditions are deliberately *not* "the run depends on code
     * it did not analyse". A prefix naming the project's own namespaces
     * classifies analysed classes and moves `coupling.cbo-app` just as well,
     * so a run whose edges all stay inside the analysed set is one where
     * prefixes bind and this channel must still speak.
     *
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof UnmatchedFrameworkNamespaceOptions || !$this->options->isEnabled()) {
            return [];
        }

        if (!$context->coversProjectScope) {
            return [];
        }

        $graph = $context->dependencyGraph;
        if ($this->coupling->isEmpty() || $graph === null) {
            return [];
        }

        $classified = self::classifiedNames($graph);
        if ($classified === []) {
            return [];
        }

        $findings = [];

        foreach ($this->coupling->unboundPrefixes($classified) as $prefix) {
            $findings[] = $this->finding($prefix);
        }

        return $findings;
    }

    private function finding(string $prefix): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: self::NAME,
            code: self::NAME,
            message: \sprintf(
                'The framework namespace "%s" matched no class the run analysed or depends on. Nothing was moved'
                . ' out of the application scope for it, so "coupling.cbo-app" still counts every class it was'
                . ' written to exclude and "coupling.ce-framework" counts none of them.',
                $prefix,
            ),
            severity: Severity::Warning,
            recommendation: \sprintf(
                'Check "%s" against the names the code really imports — a prefix is written as the namespace'
                . ' appears in the source ("Symfony\\Component\\Console"), without a leading backslash. Drop the'
                . ' entry if the dependency is gone.',
                $prefix,
            ),
        );
    }

    /**
     * Every name the coupling walk classifies, source and target alike, in the
     * FQCN spelling `CouplingCollector::isFrameworkSymbol()` builds. Empty
     * when the graph carries no edge, which is the run this channel has
     * nothing to say about.
     *
     * The conversion is repeated rather than shared with that collector: the
     * collector's walk belongs to the Collection phase and this to the
     * parent's rule pass.
     *
     * @return list<string>
     */
    private static function classifiedNames(DependencyGraphInterface $graph): array
    {
        $names = [];

        foreach ($graph->getAllDependencies() as $dependency) {
            foreach ([$dependency->sourceLogical(), $dependency->targetLogical()] as $symbolPath) {
                $namespace = $symbolPath->namespace ?? '';
                $type = $symbolPath->type ?? '';

                if ($namespace === '' && $type === '') {
                    continue;
                }

                $names[] = $namespace !== '' ? $namespace . '\\' . $type : $type;
            }
        }

        return $names;
    }
}
