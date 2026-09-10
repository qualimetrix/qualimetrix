# Coupling

## Subject and boundary

`Analysis\\Evidence\\Coupling` owns graph-derived coupling evidence, RFC
collection, threshold options and rules, and framework namespace
classification for `coupling.cbo-app` and `coupling.ce-framework`. It consumes the public
DependencyModel, Measurement, Finding, ConfigurationDocument, and neutral Core
contracts; it publishes one configuration contract for the runtime adapter.

`CouplingAnalysis` owns the framework-prefix state for one analysis run. Each
configuration document replaces the previous list, including an empty
`coupling.frameworkNamespaces` contribution, so sequential runs cannot leak
classification state.

## Metrics

Coupling metrics measure dependencies between components. All collectors in this category use `GlobalContextCollectorInterface` and work with the dependency graph.

---

## Coupling (Ca, Ce, Instability)

**Collector:** `CouplingCollector`
**Type:** `GlobalContextCollectorInterface`
**Provides:** `coupling.ca`, `coupling.ce`, `coupling.cbo`, `coupling.instability`, `coupling.ce-packages`, `coupling.cbo-app`, `coupling.ce-framework`
**Level:** Class

### Metrics

| Metric                  | Description                                           | Formula                           |
| ----------------------- | ----------------------------------------------------- | --------------------------------- |
| `coupling.ca`           | Afferent Coupling — incoming dependencies             | count(dependents)                 |
| `coupling.ce`           | Efferent Coupling — outgoing dependencies             | count(dependencies)               |
| `coupling.cbo`          | Coupling Between Objects (C&K) — all dependencies     | \|Ca ∪ Ce\|                       |
| `coupling.instability`  | Class instability (Qualimetrix extension)             | Ce / (Ca + Ce)                    |
| `coupling.ce-packages`  | Distinct external top-level namespaces in Ce          | count(distinct external packages) |
| `coupling.cbo-app`      | Application-only CBO (excludes framework deps)        | \|Ca_app ∪ Ce_app\|               |
| `coupling.ce-framework` | Framework efferent coupling (outgoing framework deps) | count(framework Ce targets)       |

> **Note:** Robert C. Martin (1994) originally defined Instability only at the **package** (namespace) level. Qualimetrix extends it to the class level for finer-grained analysis. The namespace-level instability is the canonical metric per Martin's specification.

> **Note:** The dependency graph is seeded with every named project class,
> interface, trait, and enum, including degree-zero declarations. CBO/Ca/Ce
> deduplicate logical endpoints, retain undeclared external targets, and remove
> PHP built-ins except structural `extends` edges. One logical class score is
> projected to every exact owned declaration so declaration controls, baseline
> identities, and fingerprints remain independent.

### Instability Interpretation

| Value | Description                                     |
| ----- | ----------------------------------------------- |
| 0.0   | Maximally stable (only incoming dependencies)   |
| 0.5   | Balanced                                        |
| 1.0   | Maximally unstable (only outgoing dependencies) |

**Stable classes (I ~ 0):** Used by many, depend on few. Difficult to change.

**Unstable classes (I ~ 1):** Used by few, depend on many. Easy to change.

### Framework CBO Distinction

The `coupling.cbo-app` and `coupling.ce-framework` metrics separate framework coupling (structural, can't be eliminated without changing framework) from application coupling (architectural, should be minimized).

**Configuration:**

```yaml
# qmx.yaml
coupling:
  framework-namespaces:
    - Symfony
    - PhpParser
    - Psr
    - Amp
```

**Namespace matching:** Boundary-aware prefix matching — `Psr` matches `Psr\Log\LoggerInterface` but NOT `PsrExtended\Custom`.

**Partition property:** `Ce = Ce_app + Ce_framework` (outgoing dependencies partition cleanly).

#### When a prefix classifies nothing

`UnmatchedFrameworkNamespaceRule` reports `coupling.unmatched-framework-namespace`
(warning, project level, one finding per prefix) for a configured prefix that no
name in the run falls under. Nothing was moved out of the application scope for
it, and before the channel existed the miss and a run with no prefix at all
produced byte-identical reports — the classification is only observable through
`coupling.cbo-app` / `coupling.ce-framework`, never through the finding set.

Two facts about the shape, both measured rather than chosen:

- **It is its own rule, not a second channel on `CboRule`.** A magnitude
  producer must give every channel a `WorseDirection`
  (`ChannelDeclarationCompilerPass`), and this finding judges no measured
  value; declaring it on `CboRule` is refused at container build.
- **It is a rule and not a `ConfigurationValidatorInterface`.** A validator's
  channel bypasses `fail_on` and can never be accepted by a baseline. A prefix
  that binds nothing is ordinary debt — a shared `qmx.yaml` may name a
  framework one repository does not use — so the finding answers to `fail_on`,
  `--disable-rule` and the baseline. Three mechanical guards hold that: the
  compiler pass refuses a channel declared by both producer kinds, the
  declared-channel fixture carries no `config-error` token, and an integration
  case pins exit 0 under `--fail-on=none` against exit 2 under
  `--fail-on=warning`.

The binding universe is `FrameworkClassificationSites::names()` — the two
positions where the collector's own walk calls `isFrameworkSymbol()`: the target
of a measured class's outgoing edge, and the source of its incoming one.
`CouplingAnalysis` owns the predicate for both answers, so the rule cannot
disagree with the metric, and the universe is asked of the collector rather than
re-derived, so it cannot be wider than the classification. It was wider once:
enumerating both ends of every edge counted a measured class whose only edge
points at unmeasured code, which the collector never classifies, so a prefix over
it read as bound while it moved no metric. A test pins the number of call sites
the enumeration mirrors.
Two preconditions keep it quiet where a prefix is inert for a reason other
than its spelling: no dependency graph at all, and a graph with no edge in
it — the collector then had nothing to classify, and a correct project-wide
`qmx.yaml` must not be reported because someone checked one self-contained
file. The precondition is deliberately not "the run depends on code it did
not analyse": a prefix naming the project's own namespaces moves
`coupling.cbo-app` too, so a run whose edges all stay inside the analysed
set is one where prefixes bind and the channel must still speak.

When no `framework-namespaces` are configured, `coupling.cbo-app` = `coupling.cbo` and `coupling.ce-framework` = 0.

**CBO rule scope:** The `coupling.cbo` rule supports a `scope` option:

```yaml
rules:
  coupling.cbo:
    scope: application  # 'all' (default, uses coupling.cbo) | 'application' (uses coupling.cbo-app)
```

`scope` is a recognized rule-level option. It selects the class-level CBO metric;
namespace CBO configuration remains under `namespace:`.

---

## Abstractness

**Collector:** `AbstractnessCollector`
**Type:** `GlobalContextCollectorInterface`
**Requires:** `size.class-count.sum`, `size.abstract-class-count.sum`, `size.interface-count.sum`, `size.implementing-enum-count.sum`, `size.trait-count.sum`
**Provides:** `coupling.abstractness`
**Level:** Namespace

### Formula

```
A = (size.abstract-class-count + size.interface-count)
  / (size.class-count + size.trait-count + size.interface-count + size.implementing-enum-count)
```

> **Note:** Enums are not a construct of Martin's 1994 model, so mapping them onto it
> is a deliberate scope adaptation for PHP. A bare literal enumeration offers no
> substitution point -- it cannot be extended, subtyped or implemented -- so it is
> neutral: excluded from the denominator rather than counted as concrete. An
> `enum X implements Y` *is* a substitution point, a concrete implementation of a
> declared contract, and stays in the denominator via `size.implementing-enum-count`.
> Without that split, a namespace holding one interface and N enums implementing it
> would report `A = 1.0` while its implementations sit right beside it. The shape of
> the formula is unchanged; only the classification of one construct is.

A namespace whose only declarations are bare enums therefore has `totalTypes = 0` and
keeps the pre-existing no-type result `A = 0.0`. Downstream namespace rules already
skip it through `minClassCount`, exactly as they skip a namespace with no declarations
at all.

The count inputs are exact discrete namespace sums. They are not fractionally distributed
over source contributors, so a namespace with one abstract class or interface and five
concrete types still computes as `1 / 6` rather than losing the abstraction in aggregation.

### Interpretation

| Value | Description                         |
| ----- | ----------------------------------- |
| 0.0   | All classes are concrete            |
| 0.5   | Balanced abstraction                |
| 1.0   | All classes are abstract/interfaces |

---

## Distance from Main Sequence

**Collector:** `DistanceCollector`
**Type:** `GlobalContextCollectorInterface`
**Requires:** `coupling.instability`, `coupling.abstractness`
**Provides:** `coupling.distance`
**Level:** Namespace

### Formula

```
D = |A + I - 1|
```

### Main Sequence

Ideal packages lie on the line `A + I = 1`:
- **High abstraction (A=1) + Stability (I=0)** = abstract interfaces
- **Low abstraction (A=0) + Instability (I=1)** = concrete implementation details

### Distance Interpretation

| Value   | Description                  |
| ------- | ---------------------------- |
| 0.0     | On the main sequence (ideal) |
| 0.1-0.3 | Acceptable                   |
| 0.3-0.5 | Needs attention              |
| 0.5+    | Problem zone                 |

### Problem Zones

**Zone of Pain (A~0, I~0):**
- Concrete and stable classes
- Many dependencies, difficult to change
- Example: God classes, legacy code

**Zone of Uselessness (A~1, I~1):**
- Abstract but unstable
- Useless abstractions without real usage
- Example: Over-engineering

---

## Aggregation

### CouplingCollector

```php
new MetricDefinition(
    name: 'coupling.instability',
    collectedAt: SymbolLevel::Class_,
    aggregations: [
        SymbolLevel::Namespace_->value => [Average],
    ],
)
```

### AbstractnessCollector

```php
new MetricDefinition(
    name: 'coupling.abstractness',
    collectedAt: SymbolLevel::Namespace_,
    aggregations: [], // Computed globally
)
```

### DistanceCollector

```php
new MetricDefinition(
    name: 'coupling.distance',
    collectedAt: SymbolLevel::Namespace_,
    aggregations: [], // Derived metric
)
```

---

## Example

```php
// Stable interface (I = 0.0, A = 1.0, D = 0.0)
interface PaymentGateway  // Ca = 10, Ce = 0
{
    public function process(Payment $p): Result;
}

// Unstable implementation (I = 1.0, A = 0.0, D = 0.0)
class StripeGateway implements PaymentGateway  // Ca = 0, Ce = 5
{
    public function __construct(
        private HttpClient $client,
        private Logger $logger,
        private Config $config,
    ) {}
}

// Problematic class in the Zone of Pain (I ~ 0, A ~ 0, D ~ 1)
class GodClass  // Ca = 20, Ce = 0 — everything depends on it
{
    // 1000+ lines of concrete logic
}
```


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
