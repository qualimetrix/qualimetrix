# Cohesion

## Subject and boundary

`Analysis\\Evidence\\Cohesion` owns the LCOM4 and TCC/LCC class-cohesion
collectors, their AST visitors and per-class calculation data, and the
`design.lcom` rule with its options. `ClassVisitorStackTrait` is private
scaffolding shared only by the two Cohesion visitors.

The leaf has no `Contract/` surface. It consumes Measurement's collector,
metric and aggregation contracts and Finding's rule, option and violation
contracts. Measurement remains the owner of cross-capability metric APIs and
the collector runtime configuration store.

## Structure

```text
Cohesion/
├── ClassVisitorStackTrait.php
├── LcomClassData.php
├── LcomCollector.php
├── LcomGraphCalculator.php
├── LcomOptions.php
├── LcomRule.php
├── LcomVisitor.php
├── TccLccClassData.php
├── TccLccCollector.php
└── TccLccVisitor.php
```

## Behaviour and runtime configuration

`LcomCollector` provides `lcom`; `TccLccCollector` provides `tcc` and `lcc`.
They retain their collector names, metric keys, class-level aggregation
definitions, visitor reset semantics, and anonymous-class exclusion.

`design.lcom` remains the stable rule ID and retains its Design category,
warning/error defaults of 3/5, readonly and minimum-method eligibility checks,
CLI aliases, and threshold-override behaviour. `LcomOptions::excludeMethods`
continues to configure the LCOM graph. The runtime adapter projects that option
to Measurement's `CollectorRuntimeConfigurationStore`; it is an invocation
seam, not a Cohesion public contract.

LCOM4 counts connected groups of instance methods through shared property
access or `$this->method()` calls. Stateless constant methods are merged into a
virtual node to avoid false positives from protocol metadata. TCC and LCC
measure direct and transitive property-sharing connections among public methods.

> **Note:** The original LCOM4 specification defines edges only through shared
> property access. Qualimetrix also creates method-call edges through
> `$this->method()` and merges stateless constant methods with no property
> access or instance method calls into one virtual node. These deliberate
> extensions prevent getter-based designs and interface-mandated metadata from
> inflating the number of disconnected components; Qualimetrix does not claim
> strict original-spec compliance.

## Test ownership and Definition of Done

Owned tests live in `tests/Analysis/Evidence/Cohesion/Unit/` and cover LCOM
data/collection, TCC/LCC data/collection, and the LCOM rule options,
eligibility, thresholds, controls and output identity.

- The three metric IDs and the `design.lcom` rule ID remain unchanged.
- `excludeMethods` affects both direct collection and configured runtime runs.
- Visitor and runtime state are replaced between files and analysis runs.
- LCOM and TCC/LCC continue to ignore anonymous classes.
