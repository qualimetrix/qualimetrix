# ADR 0106: Declaration metric records and declared publication

## Status

Accepted

## Context

[ADR 0021](0021-declaration-scoped-callable-identity-and-dependency-projections.md)
separates physical declarations from the logical names used by dependency
graphs. Class metrics still mixed those populations: a logical class bag could
combine methods from several same-name declarations, while reporting assigned
its values and finding counts to each declaration. Some exported suffixes and
population counters also had no owning definition.

[ADR 0069](0069-a-metric-is-declared-by-the-collector-that-writes-it.md)
requires a metric to be declared by its writer. That promise must include the
publication level and suffixes, the area in which a class key may be stored,
and the population used by aggregation. A successfully serialized document
does not establish those properties.

## Decision

### One record per physical declaration

Class values are read through an exact declaration subject. Exported class and
callable records carry a canonical `subject`; `name` remains the logical name
and may repeat. Their source file and line belong to that declaration.
Namespace and project records have no single declaration location and publish
`file: null` and `line: null`.
Their findings likewise have no borrowed source location. Path suppression
therefore cannot select these findings; namespace suppression names the actual
namespace. GitLab Code Quality and Checkstyle omit ordinary findings without a
source file rather than inventing a path. JSON and SARIF retain the complete
finding population; coverage failures keep their separate format projection.

The repository exposes `allClassDeclarations()` for the value population and
`allLogicalClasses()` for the graph population. Logical `get()` and `has()`
refuse class and callable paths even when only one declaration is known. A
first-declaration fallback would make correctness depend on file discovery
order and would hide a missing identity.

### Declared class areas and publication

Each class metric definition declares `Declaration` or `LogicalName` scope.
Facts of one declaration, callable aggregates, derived declaration facts and
class computed values belong to the declaration area. Coupling graph values,
ClassRank and NOC belong to the logical-name area. An exact declaration view
combines its own values with its name's graph values without copying the latter
into declaration storage.

An exportable class scalar without a definition, a write into the wrong area,
or a contradictory registry during merge refuses. Identity-only bags and
private structured collection metadata do not grant permission to publish an
undeclared scalar.

`MetricDefinition::publicationLevels()` declares the levels that publish the base
key directly: its collected level and finite additional levels. Repeated levels
refuse. Generic namespace-provider catalog assembly adds Namespace only to File
definitions, preserving all metadata; collection levels still select samples.

`MetricDefinition::publishedSuffixes()` is the authority for aggregate suffixes,
including the sample count accompanying an average. Measurement declares its
population metrics. Namespace contributions declare their file population;
their internal file-count marker is not a published metric suffix. A marked
contribution definition refuses strategies that cannot be reconstructed from
its totals and file counts.

Infrastructure composes the measured definition catalog and the computed
definition catalog when creating a fresh repository. It resolves definitions
after invocation configuration, rather than freezing computed metadata during
container compilation. Measurement does not import ComputedMetrics policy.

### One declaration population for aggregation

Class samples, class counts and health coverage use the same declaration
population. Per-file producers retain every physical declaration before the
exact join; a logical name is not a sufficient storage key for those facts.
Logical graph records remain available for graph identity but do not become an
additional namespace aggregation sample. A logical graph value for a name with several declarations appears
in each declaration view and contributes once per declaration to class-derived
namespace aggregates. This is an explicit sampling adaptation: the graph still
has one logical node, while the reported class population has physical
declarations. Graph algorithms and their scaling retain the logical population.

Namespace file contributions are folded as `(total, files)` pairs. Sums preserve
integer totals; counts sum files; averages divide the accumulated totals by the
accumulated file count. Each namespace's own contribution enters a subtree once;
an already completed child subtree is not added again.

### Exact callable ownership and finding attribution

Named-class methods and property hooks carry both a logical aggregation owner
and its exact declaration. Registration, cloning and merge preserve that pair.
Lexical class context alone is not aggregation ownership: anonymous-class
methods, closures and global functions can have a valid absent owner pair.

Class finding counts use the finding's exact class subject or the exact
callable's declared owner. A missing required callable entry, an incomplete
owner pair or mismatched logical identity refuses before partial counts are
returned. It must not become a zero count or fan out to every same-name class.

WMC belongs to Complexity. The existing shared AST traversal records named
class-like declarations, including those without callables. Each gets WMC zero
before the complexities of its owned methods are summed. Property hooks and
anonymous-class methods do not contribute to that named declaration's WMC.

### Consumers

Reports retain one captured `FileNamespaceIndex`. Namespace selection,
grouping and finding records use that same index: declarations keep their own
namespace, file findings carry every namespace declared in the file (global
when none is declared), and project findings carry none. A multi-namespace
file finding appears once in a sorted group rather than once per namespace.
JSON violations, top issues and HTML publish `namespaces` as the full list;
`namespace` is its sole member or null. The global namespace is the empty
string, not its display label `(global)`.

Metric exports, health summaries and class drill-downs use exact declaration
subjects. HTML class IDs and links use the subject; the logical name remains
display text. Graph and identity-only consumers explicitly choose logical
names. Cross-tool comparisons compare names only when that name identifies one
declaration and report duplicates separately.

## Consequences and migration

- Index metric records and HTML class links by `subject`, not by a unique
  `(type, name)` assumption. Regenerate saved report links.
- Use `getSubject()` and declaration enumeration for class or callable values;
  use logical enumeration only for graph names.
- Preserve the captured namespace index when constructing or copying reports.
  Read `namespaces` for multi-namespace file findings; use `namespace` only
  when it is non-null. The metrics symbol export keeps its existing fields.
- Supply the owning definitions before writing exportable class scalars into a
  standalone repository. Namespace file counts are internal contribution data,
  not undeclared `.count` metrics.
- Expect WMC zero for named class-like declarations without methods and null
  source locations on namespace and project records.
- Migrate namespace-finding suppressions from `suppress_paths` to
  `suppress_namespaces`. Use JSON or SARIF when a consumer needs findings without
  source files; GitLab Code Quality and Checkstyle carry the source-located subset.
- Same-name declarations can have different values, finding counts and
  densities. Their graph-only values remain shared by logical name.

This does not change the base formulas of the metrics or make dynamic
`eval`/`class_alias` declarations part of static discovery. General offender
ranking and health formula calibration are separate responsibilities.

Native store construction remains in the private Measurement factory. The
existing public factory promise accepts finite definitions; Infrastructure
resolves its two catalogs and delegates to an independently bound storage
factory service. A native cross-owner constructor call is not a Symfony
composition binding, so it does not authorize importing the private repository.
