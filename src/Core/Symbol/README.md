# Core Symbol

Symbol values provide stable neutral identities for declarations and aggregates.
Their direct public surface is intentional: these values are the subject, not
adapters hidden behind a role-specific contract directory.

`ClassNameSpelling::fold()` folds only ASCII uppercase bytes, leaving high-byte
identifier bytes untouched. `canonical()` chooses the byte-smallest observed
spelling. `MixedSpelling` carries at least two spellings of one folded identity
and its canonical projection: class and namespace groups use that observed
minimum; an external group may use the exact installed declaration's spelling
within the same identity. These values carry evidence and perform no logging.

`PhpBuiltinClassRegistry` and `PhpBuiltinClassHierarchy` state what PHP itself
declares — which names are PHP's, and what is above each of them — as static
tables, so no answer depends on the PHP that runs the analysis. Governance
censuses in `governance/SymbolVocabulary/` compare both with the running PHP
and refuse on divergence; neither table is generated.

PHP folds class names by ASCII case, so both tables answer a name in any case
spelling: `PhpBuiltinClassRegistry::canonicalName()` maps it to the spelling the
list keeps, and every lookup goes through it. Callers strip a leading `\`
themselves. `PhpBuiltinClassRegistry::spelling()` is the name a caller records
a class under — that spelling for a PHP class, the written name for any other —
for readers that compare class names by exact string.

`TraitComposition/` owns PHP trait method composition, independently of symbol
identity. Its `TraitComposition::compose()` calculates PHP's effective imported method names
from neutral facts. Coupling uses their source identities for RFC membership;
Cohesion can use the same identities and effective visibility to find method
bodies. The primitive owns no AST traversal, declaration lookup, repository or
metric arithmetic. Its state lasts for one composition call.

```
Symbol/
└── TraitComposition/
    ├── TraitComposition.php        # Composition entry point
    ├── TraitCompositionFailure.php # Invalid source composition and declaration
    ├── TraitCompositionResult.php  # Imported method map and unread trait names
    ├── TraitCompositionScope.php   # Internal recursive reads and cycle detection
    ├── TraitDefinitionFacts.php    # Raw own methods and nested uses of one trait
    ├── TraitMethodPool.php         # Internal direct candidates and adaptations
    ├── TraitMethodSource.php       # Original method and effective modifiers
    └── TraitUseFacts.php           # Resolved traits, aliases and precedences
```

The entry point accepts all own method names (including abstract methods),
`list<TraitUseFacts>`, and `callable(string): ?TraitDefinitionFacts`. The callback
reads raw facts for exactly one resolved trait declaration. `null` means missing
or unreadable; an empty `TraitDefinitionFacts` means a known empty trait. Raw own
methods carry original visibility and `isAbstract`, and nested uses carry the
same facts as top-level uses. The callback does not compose nested methods.

`TraitCompositionResult::methods` maps ASCII-folded effective names to
`TraitMethodSource`. It contains imported methods only: own names override
imports and are absent from this map. The source retains the final declaring
trait and original method spelling even through nested aliases. Adaptation
qualifiers refer to direct used traits, which can differ from that final source.
All use statements contribute to one direct candidate pool. `insteadof` filters
original imports; aliases search the original pool, including excluded sources.
An unqualified alias needs a unique direct source. Visibility-only adaptations
change the original import; aliases without explicit visibility keep the raw
source visibility independently of those changes.

Concrete methods satisfy abstract candidates of the same name. Identical diamond
imports are one method; different concrete sources, different effective
visibility, or colliding concrete aliases throw `TraitCompositionFailure`
naming the method. PHP signature compatibility and type-system validation are
outside this contract. Unread nested traits retain known independent methods.
Active cycle members contribute no methods and appear in `unread`; repeated DAG
reads are cached per call and are not cycles. Unread names are deduplicated by
ASCII identity. An unqualified alias with an unread candidate remains unresolved
instead of guessing its source.

The owning tests are in `tests/Core/Symbol/Unit/TraitComposition/TraitCompositionTest.php`. Keep
these facts neutral as further consumers are added; a sole remaining capability
consumer would require reassessing ownership rather than importing its state
into Core.
