<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

/**
 * Canonical metric name constants shared between collectors and rules.
 *
 * A key is `family.metric` in kebab, where the family is the subject the metric
 * belongs to rather than the collector that happens to produce it: the seven
 * class-shape facts below are `design.*` although Size writes them, and that
 * mismatch is a defect of the layout recorded in AUDIT.md, not of the name.
 *
 * The constant is the key upper-cased, so a constant cannot drift from the
 * family of its own value the way `STRUCTURE_LCOM = 'lcom'` did.
 *
 * @qmx-threshold coupling.cbo 67 -- Canonical names are an intentional Measurement contract hub, and this CBO is afferent: it counts adoption, not entanglement. Current raw CBO 66 gets one-edge headroom. It rose from 64 when Ш5e3 moved the eleven collector-owned counters here and gave the aggregated-key decomposition a home beside its inverse.
 */
final class MetricName
{
    // -- Complexity ------------------------------------------------------------

    public const string COMPLEXITY_CCN = 'complexity.ccn';
    public const string COMPLEXITY_COGNITIVE = 'complexity.cognitive';
    public const string COMPLEXITY_NPATH = 'complexity.npath';
    public const string COMPLEXITY_WMC = 'complexity.wmc';

    // -- Coupling --------------------------------------------------------------

    public const string COUPLING_CA = 'coupling.ca';
    public const string COUPLING_CE = 'coupling.ce';
    public const string COUPLING_CBO = 'coupling.cbo';
    public const string COUPLING_INSTABILITY = 'coupling.instability';
    public const string COUPLING_ABSTRACTNESS = 'coupling.abstractness';
    public const string COUPLING_DISTANCE = 'coupling.distance';
    public const string COUPLING_CLASS_RANK = 'coupling.class-rank';
    public const string COUPLING_CE_PACKAGES = 'coupling.ce-packages';
    public const string COUPLING_CBO_APP = 'coupling.cbo-app';
    public const string COUPLING_CE_FRAMEWORK = 'coupling.ce-framework';
    public const string COUPLING_RFC = 'coupling.rfc';
    public const string COUPLING_RFC_OWN = 'coupling.rfc-own';
    public const string COUPLING_RFC_EXTERNAL = 'coupling.rfc-external';

    // -- Cohesion --------------------------------------------------------------

    public const string COHESION_TCC = 'cohesion.tcc';
    public const string COHESION_LCC = 'cohesion.lcc';
    public const string COHESION_PURE_METHOD_COUNT = 'cohesion.pure-method-count';
    public const string COHESION_LCOM = 'cohesion.lcom';

    // -- Design ----------------------------------------------------------------

    public const string DESIGN_TYPE_COVERAGE_PARAM_TOTAL = 'design.type-coverage.param.total';
    public const string DESIGN_TYPE_COVERAGE_PARAM_TYPED = 'design.type-coverage.param.typed';
    public const string DESIGN_TYPE_COVERAGE_PARAM = 'design.type-coverage.param';
    public const string DESIGN_TYPE_COVERAGE_RETURN_TOTAL = 'design.type-coverage.return.total';
    public const string DESIGN_TYPE_COVERAGE_RETURN_TYPED = 'design.type-coverage.return.typed';
    public const string DESIGN_TYPE_COVERAGE_RETURN = 'design.type-coverage.return';
    public const string DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL = 'design.type-coverage.property.total';
    public const string DESIGN_TYPE_COVERAGE_PROPERTY_TYPED = 'design.type-coverage.property.typed';
    public const string DESIGN_TYPE_COVERAGE_PROPERTY = 'design.type-coverage.property';
    public const string DESIGN_TYPE_COVERAGE_PCT = 'design.type-coverage.pct';
    public const string DESIGN_DIT = 'design.dit';
    public const string DESIGN_IS_READONLY = 'design.is-readonly';
    public const string DESIGN_IS_PROMOTED_PROPERTIES_ONLY = 'design.is-promoted-properties-only';
    public const string DESIGN_IS_DATA_CLASS = 'design.is-data-class';
    public const string DESIGN_NOC = 'design.noc';
    public const string DESIGN_IS_ABSTRACT = 'design.is-abstract';
    public const string DESIGN_IS_INTERFACE = 'design.is-interface';
    public const string DESIGN_IS_EXCEPTION = 'design.is-exception';
    public const string DESIGN_WOC = 'design.woc';

    // -- Maintainability -------------------------------------------------------

    public const string MAINTAINABILITY_HALSTEAD_VOLUME = 'maintainability.halstead.volume';
    public const string MAINTAINABILITY_HALSTEAD_DIFFICULTY = 'maintainability.halstead.difficulty';
    public const string MAINTAINABILITY_HALSTEAD_EFFORT = 'maintainability.halstead.effort';
    public const string MAINTAINABILITY_HALSTEAD_BUGS = 'maintainability.halstead.bugs';
    public const string MAINTAINABILITY_HALSTEAD_TIME = 'maintainability.halstead.time';
    public const string MAINTAINABILITY_MI = 'maintainability.mi';

    // -- Security --------------------------------------------------------------

    public const string SECURITY_HARDCODED_CREDENTIALS = 'security.hardcoded-credentials';
    public const string SECURITY_SENSITIVE_PARAMETER = 'security.sensitive-parameter';

    // -- Size ------------------------------------------------------------------

    public const string SIZE_CLASS_LOC = 'size.class-loc';
    public const string SIZE_CLASS_COUNT = 'size.class-count';
    public const string SIZE_ABSTRACT_CLASS_COUNT = 'size.abstract-class-count';
    public const string SIZE_INTERFACE_COUNT = 'size.interface-count';
    public const string SIZE_TRAIT_COUNT = 'size.trait-count';
    public const string SIZE_ENUM_COUNT = 'size.enum-count';
    public const string SIZE_IMPLEMENTING_ENUM_COUNT = 'size.implementing-enum-count';
    public const string SIZE_FUNCTION_COUNT = 'size.function-count';
    public const string SIZE_LOC = 'size.loc';
    public const string SIZE_LLOC = 'size.lloc';
    public const string SIZE_CLOC = 'size.cloc';
    public const string SIZE_METHOD_STATEMENT_COUNT = 'size.method-statement-count';
    public const string SIZE_METHOD_COUNT_PUBLIC = 'size.method-count.public';
    public const string SIZE_METHOD_COUNT_PROTECTED = 'size.method-count.protected';
    public const string SIZE_METHOD_COUNT_PRIVATE = 'size.method-count.private';
    public const string SIZE_GETTER_COUNT = 'size.getter-count';
    public const string SIZE_SETTER_COUNT = 'size.setter-count';
    public const string SIZE_PROPERTY_COUNT_PUBLIC = 'size.property-count.public';
    public const string SIZE_PROPERTY_COUNT_PROTECTED = 'size.property-count.protected';
    public const string SIZE_PROPERTY_COUNT_PRIVATE = 'size.property-count.private';
    public const string SIZE_PROMOTED_PROPERTY_COUNT = 'size.promoted-property-count';
    /**
     * Symbol counters the aggregation pipeline injects at namespace and project
     * level, where they serve as denominators in the built-in health formulas.
     */
    public const string SIZE_SYMBOL_METHOD_COUNT = 'size.symbol-method-count';
    public const string SIZE_SYMBOL_CLASS_COUNT = 'size.symbol-class-count';
    public const string SIZE_METHOD_COUNT = 'size.method-count';
    public const string SIZE_METHOD_COUNT_TOTAL = 'size.method-count.total';
    public const string SIZE_PROPERTY_COUNT = 'size.property-count';

    // -- Code smell ------------------------------------------------------------

    public const string CODE_SMELL_UNUSED_PRIVATE_TOTAL = 'code-smell.unused-private.total';
    public const string CODE_SMELL_UNUSED_PRIVATE_METHOD = 'code-smell.unused-private.method';
    public const string CODE_SMELL_UNUSED_PRIVATE_PROPERTY = 'code-smell.unused-private.property';
    public const string CODE_SMELL_UNUSED_PRIVATE_CONSTANT = 'code-smell.unused-private.constant';
    public const string CODE_SMELL_PARAMETER_COUNT = 'code-smell.parameter-count';
    public const string CODE_SMELL_IS_VO_CONSTRUCTOR = 'code-smell.is-vo-constructor';
    public const string CODE_SMELL_UNREACHABLE_CODE = 'code-smell.unreachable-code';
    public const string CODE_SMELL_UNREACHABLE_CODE_FIRST_LINE = 'code-smell.unreachable-code.first-line';

    /**
     * Returns the aggregated metric name for a base metric and strategy.
     *
     * Example: agg(COMPLEXITY_CCN, AggregationStrategy::Sum) → 'complexity.ccn.sum'
     */
    public static function agg(string $metric, AggregationStrategy $strategy): string
    {
        return $metric . '.' . $strategy->value;
    }

    /**
     * The base metric an aggregated name was built from, or the name itself.
     *
     * The inverse of {@see agg()}, and it lives beside it because the two are
     * one rule read in two directions. Apart, the decomposition drifted: it
     * used to cut at the first dot, which was already wrong for
     * `maintainability.halstead.volume` and matched nothing at all once every
     * key carried its family. The suffix is a strategy or it is part of the
     * name — there is no third case.
     *
     * Example: base('complexity.ccn.sum') → 'complexity.ccn'
     */
    public static function base(string $metric): string
    {
        $lastDot = strrpos($metric, '.');

        if ($lastDot === false) {
            return $metric;
        }

        return AggregationStrategy::tryFrom(substr($metric, $lastDot + 1)) === null
            ? $metric
            : substr($metric, 0, $lastDot);
    }

    private function __construct()
    {
        // Static-only class
    }
}
