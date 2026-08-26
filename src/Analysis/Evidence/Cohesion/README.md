# Cohesion

## Subject and boundary

`Analysis\\Evidence\\Cohesion` owns the LCOM4 and TCC/LCC class-cohesion
collectors, their AST visitors and per-class calculation data, the
`cohesion.lcom` rule with its options, and the LCOM collection configuration
needed by its named sequential and parallel consumers.
`ClassVisitorStackTrait` is private scaffolding shared only by the two Cohesion
visitors.

`Contract/Configuration/` exposes the exact
`LcomCollectionConfiguration` value; `Runtime/` owns its scoped store and
resolver. Measurement remains the owner of cross-capability metric and
aggregation contracts, while Finding owns rule options and findings.

## Structure

```text
Cohesion/
├── ClassVisitorStackTrait.php
├── Contract/
│   └── Configuration/       # LCOM configuration promise for named consumers
├── LcomClassData.php
├── LcomCollector.php
├── LcomGraphCalculator.php
├── LcomOptions.php
├── LcomRule.php
├── LcomVisitor.php
├── Runtime/                 # LCOM store and owner-local resolver
├── TccLccClassData.php
├── TccLccCollector.php
└── TccLccVisitor.php
```

## Behaviour and runtime configuration

`LcomCollector` provides `lcom`; `TccLccCollector` provides `tcc` and `lcc`.
They retain their collector names, metric keys, class-level aggregation
definitions, visitor reset semantics, and anonymous-class exclusion.

`cohesion.lcom` is the stable rule ID — its `cohesion` family, and so the
heading `qmx rules` lists it under, is now read off that name rather than
declared beside it (renamed from Design, see ADR). It carries warning/error
defaults of 3/5, readonly and
minimum-method eligibility checks, CLI aliases, and threshold-override
behaviour. `LcomOptions::excludeMethods`
continues to configure the LCOM graph. Finding resolves the effective rule
configuration, and Cohesion projects its exact value to
`LcomCollectionConfiguration`. The instance-owned
`LcomCollectionConfigurationStore` supplies the main collector and the typed
worker payload; it is Cohesion-owned invocation state with an explicit reset
point.

LCOM4 counts connected groups of instance methods through shared property
access or `$this->method()` calls. Constructors and destructors are excluded
from the graph entirely (see the deviation note below — a constructor whose
assigned fields no other stateful method reads never shares a property-access
edge with the rest of the class, so leaving it in would inflate LCOM; property
promotion is the guaranteed case, affecting the majority of PHP 8+
constructors). Stateless constant methods are merged into a virtual node to
avoid false positives from protocol metadata.
TCC and LCC measure direct and transitive property-sharing connections among
public methods.

> **Note:** The original LCOM4 specification defines edges only through shared
> property access. Qualimetrix also creates method-call edges through
> `$this->method()` and merges stateless constant methods with no property
> access or instance method calls into one virtual node. These deliberate
> extensions prevent getter-based designs and interface-mandated metadata from
> inflating the number of disconnected components; Qualimetrix does not claim
> strict original-spec compliance. Constructors and destructors are excluded
> from the method set entirely, matching the treatment TCC/LCC already applies.
> A constructor whose assigned fields no other stateful method reads shares no
> property-access edge with the rest of the class, so without the exclusion it
> would sit in the graph as an isolated vertex and inflate LCOM by one.
> Property promotion (`private array $x` in the parameter list) is the
> guaranteed case — a promoted parameter never emits a property-access node at
> all — affecting the large majority of constructors in modern PHP 8+ code.
> Recording promoted parameters as property accesses was rejected: it would
> turn `__construct` into a hub touching every promoted property, connecting
> unrelated methods through it and pushing LCOM toward 1 almost everywhere.
> The exclusion is not one-directional: reverting it on php-parser's own
> source (0 promoted constructors) changed LCOM for 106 of 260 classes — 97
> dropped (the artifact this fix targets) and 9 *rose* (a real disconnection
> the constructor's edges had been masking), moving `health.cohesion` from
> 60.40 to 63.13.

## Test ownership and Definition of Done

Owned tests live in `tests/Analysis/Evidence/Cohesion/Unit/` and cover LCOM
data/collection, TCC/LCC data/collection, and the LCOM rule options,
eligibility, thresholds, controls and output identity.

- The three metric IDs and the `cohesion.lcom` rule ID remain unchanged.
- `excludeMethods` affects both direct collection and configured runtime runs.
- Visitor and runtime state are replaced between files and analysis runs.
- LCOM and TCC/LCC continue to ignore anonymous classes.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
