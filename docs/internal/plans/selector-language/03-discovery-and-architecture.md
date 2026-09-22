# Stage 3 — discovery pruning and the Architecture exception

These packages are independent after Stage 1. They are grouped because both
currently carry a second matching language, but they do not share an owner or a
runtime abstraction.

## P6 — Run-owned directory pruning

**Production files:**

- add `src/Analysis/Run/Discovery/DirectoryPruner.php`
- change `src/Analysis/Run/Discovery/FinderFileDiscovery.php`
- change `src/Analysis/Run/Discovery/FileDiscoveryFactory.php`
- change `src/Analysis/Run/Contract/Discovery/FileDiscoveryFactoryInterface.php`
- change `src/Analysis/Run/ExcludeBinding/ExcludeBindingProbe.php`
- change `src/Analysis/Run/ExcludeBinding/UnmatchedExcludeAudit.php`
- change Run configuration values carrying `exclude`
- change `src/Infrastructure/Console/AnalysisPreflight.php`
- change `src/Infrastructure/Console/MeasuredFindingSet.php`
- change `src/Infrastructure/Console/LayerAssignmentResolver.php`
- change `src/Infrastructure/Git/GitScopeResolver.php`
- change every test double and call site of
  `FileDiscoveryFactoryInterface::create()` found by the re-derived census
- update `src/Analysis/Run/README.md`

**Test files:** Run Discovery and ExcludeBinding Unit/Integration tests plus
Console functional tests for `exclude`/`--exclude`.

**Contract:** selectors see each directory as a project-relative `/` path. The
factory receives `RunConfiguration::projectRoot` explicitly beside the bound
path patterns. The pruner evaluates a directory before descent and returns the first selector that
matched; discovery skips that subtree. The binding probe consumes the same
pruner decision and therefore deletes its reimplementation of Symfony Finder's
basename/segment grammar. Built-in exclusions become explicit internal subtree
or regex definitions whose meaning is tested (`vendor`, `node_modules`, `.git`
at any depth) rather than inherited accidentally from Finder.

Direct file arguments remain exact inputs and are not excluded as directories.
Overlapping roots retain deduplication and stable sort order. A selector beneath
an already-pruned parent remains unjudgeable, not falsely unbound. Scope is
defined relative to the project root, not separately per input root, so the same
selector means the same thing for `qmx check .` and `qmx check src`.

**Gate:** a table-driven test compares pruning and binding attribution over root,
nested, sibling-prefix, metacharacter, Windows-separator-normalization, overlapping
root, Git-scoped input, debug layer assignment, and built-in-prune cases. Finder
no longer receives user exclusions.

## P7 — Architecture-owned capture and binding DSL

**Production files:**

- `src/Analysis/Policy/Architecture/Layer/CapturePattern.php`
- `src/Analysis/Policy/Architecture/Layer/LayerCriteriaMatcher.php`
- `src/Analysis/Policy/Architecture/Layer/PatternScope.php`
- `src/Analysis/Policy/Architecture/Layer/LayerShadowing.php`
- `src/Analysis/Policy/Architecture/Layer/TemplateLayerDefinition.php`
- `src/Analysis/Policy/Architecture/Layer/Expansion/TupleExtractor.php`
- `src/Analysis/Policy/Architecture/Configuration/LayerCriterionNormalizer.php`
- `src/Analysis/Policy/Architecture/Configuration/LayersValidator.php`
- `src/Analysis/Policy/Architecture/Configuration/ExcludeBlockValidator.php`
- `src/Analysis/Policy/Architecture/Configuration/Allow/LayerSelector.php`
- `src/Analysis/Policy/Architecture/Configuration/Allow/LayerSelectorParser.php`
- Architecture README and relevant contract docs

**Test files:** Architecture Layer, Configuration, Expansion, and Allow tests and
fixtures. No Core selector type is imported into capture/binding code.

**Canonical DSL:**

- a bare FQN pattern keeps the Architecture DSL's boundary-aware subtree meaning,
  and that meaning is identical in static and template-expanded membership;
- namespace membership uses `*` for zero-or-more non-separator characters,
  `?` for one non-separator character, and `**` for zero-or-more characters
  across namespace segments;
- `[`, `]`, raw PCRE syntax, unnamed braces, duplicate capture names, and a
  capture in a static layer declaration are refused rather than interpreted
  differently by separate consumers;
- `{name:*}` and `{name:**}` retain tuple extraction and substitution semantics;
- wildcard patterns are full-subject. A trailing `\\**` denotes strict
  descendants, while the bare FQN already includes the root and descendants;
  `PatternScope` and `LayerShadowing` model these same sets;
- allow selectors keep exact/glob/captured binding semantics over layer names,
  but their wildcard alphabet and refusal rules are stated independently because
  layer names do not use namespace separators.

Concrete layer names forbid `\`, so `LayerSelectorParser` refuses `{name:**}` in
the allow DSL as dead and misleading syntax. In membership templates, `:**` is
also refused for a variable referenced by the layer-name template because its
multi-segment value cannot form a legal concrete name. It remains valid only for
capture variables used by membership/exclude substitution and not by the name;
the expansion suite includes a genuinely multi-segment witness for that case.

**Gate:** positive membership, exclude tuple reuse, declaration order, source-to-
target binding, static-brace refusal, wildcard separator behavior, and character-
class refusal agree across validators and runtime. The README no longer calls
the DSL equivalent to Core glob matching.
