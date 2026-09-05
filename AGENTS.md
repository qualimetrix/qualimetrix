# AGENTS.md — Guide for AI Agents

**Qualimetrix** — a CLI tool for static analysis of PHP code

---

## Language Policy

The repository language is **English**. All commits, documentation, code comments, docblocks, CLI output strings, and any other text must be written in English. Do not use any other language.

---

## Development Environment

The project is developed with the help of an AI agent in two environments:
- **Locally** — Claude Code CLI on macOS
- **Remotely** — [Claude Code on the Web](https://claude.ai/code) (Ubuntu)

When starting a session in the web environment, `scripts/init-environment.sh` is automatically executed (via the SessionStart hook), which installs the required dependencies and tools.

---

## Required Reading

**Before starting work:**
1. This file (AGENTS.md) — working rules
2. [ARCHITECTURE.md](docs/ARCHITECTURE.md) — understanding the architecture
3. README.md in the corresponding `src/` directory for the current task

**Before implementing a component:**
- Read README.md in the corresponding `src/` directory
- Check the Definition of Done at the end of the document
- Study the related interfaces in `src/Core/README.md`

**After implementing a component:**
- Update README.md in the affected `src/` directory: add new files/classes to the structure diagram, update descriptions
- If default thresholds changed, update the owning capability README and its
  `website/docs/rules/{group}.md` page

**Before planning a new feature:**
- Read [docs/internal/PRODUCT_VISION.md](docs/internal/PRODUCT_VISION.md) — target users, design principles, scope boundaries

**Before adding CLI commands or options:**
- Read [docs/internal/CLI_CONVENTIONS.md](docs/internal/CLI_CONVENTIONS.md) — naming rules

**Before updating website documentation:**
- Read [website/CONTRIBUTING_DOCS.md](website/CONTRIBUTING_DOCS.md) — structure and style rules

---

## Project Structure

The project's current architectural direction is the **capability-oriented
modular monolith** accepted in
[ADR 0022](docs/adr/0022-capability-oriented-modular-monolith.md). It supersedes
ADR 0012's substantial/thin hybrid direction; ADR 0010 is the historical
Architecture pilot and ADR 0016 remains the governing subject-cohesion rule.

The accepted capability boundaries distribute the former Metrics and Rules role
buckets among eight evidence capabilities. The tree below describes the current
physical layout. P0 governance remains live: the versioned internal manifest is
authoritative for every production declaration and its semantic owner. It
generates a coarse qmx projection with one layer per owner, no singleton
enforcement seams, final `external`, and permanent exact composition bindings
that retain the coarse owner pairs the projection would otherwise lose. Counts
live in `docs/internal/generated/modular-architecture/`, never in prose.

```
src/
├── Core/              # Cross-cutting primitives (no dependencies)
├── Analysis/          # Orchestration plus taxonomy-only capability grouping
│   ├── Configuration/       # ordered configuration document resolution
│   ├── Finding/             # rule language, execution, findings and filtering
│   ├── Evidence/
│   │   ├── DependencyModel/     # graph model plus P3 extraction/traversal contract
│   │   ├── Duplication/         # detection, result and rule; implements the Run-owned FileSet port
│   │   ├── CircularDependency/  # P4 SCC evidence, rule and preparation contract
│   │   ├── ComputedMetrics/      # P5 formulas, instance-owned catalog and Health semantics
│   │   ├── CodeSmell/            # code-smell collection and rules
│   │   ├── Cohesion/             # class cohesion evidence and rules
│   │   ├── Complexity/           # cyclomatic, cognitive and NPath evidence and rules
│   │   ├── Coupling/             # coupling evidence, rules and run configuration
│   │   ├── Design/               # DataClass, GodClass, Inheritance (with NOC), TypeCoverage
│   │   ├── Maintainability/      # Halstead and maintainability evidence and rules
│   │   ├── Measurement/         # collection facts, repository, attribution, aggregation
│   │   ├── Prioritization/      # impact ranking and technical-debt evidence
│   │   ├── Security/             # security evidence and rules
│   │   └── Size/                 # size evidence and rules
│   ├── Policy/
│   │   ├── Architecture/        # P4 declared-layer policy and debug contracts
│   │   ├── Baseline/            # accepted-finding ceiling lifecycle
│   │   └── Inline/              # source annotations and suppression controls
│   └── Run/                 # P3 discovery, collection, phase ordering and FileSet port
├── Reporting/         # formatters plus GraphProjection and FindingProjection
└── Infrastructure/    # Adapters (CLI, DI, cache, git, profiler) — adapters for any feature live here
benchmarks/            # Benchmark PHP projects for metric calibration (see benchmarks/README.md)
scripts/               # Utility scripts (benchmark data collection, regression checks)
```

Each domain has its own `README.md` with detailed structure, classes, and contracts.

### Decision framework for new capabilities

**The underlying rule is subject cohesion ([ADR 0016](docs/adr/0016-subject-cohesion.md)):
a directory is a subject, not a role.** Its name must answer "what is this
about?" without naming a technical role, base class, or interface. Three tests:

1. **Naming** — if "this directory is about ___" can only be completed with "the
   classes implementing X", it is a role bucket.
2. **Co-change** — a change to one subject should touch one directory plus its
   adapters. Checkable against git history.
3. **Duplication** — if the tree were fully decomposed by subject, which
   directories would have to be *copied into every subject*? Those are the
   legitimate cross-cutting ones (`Core/`, `Reporting/`, `Infrastructure/`,
   `Analysis/`). Anything that would instead move wholesale into one subject is
   a role bucket.

Two corollaries that settle recurring arguments:

- A contract stays with the module promising it to named consumers. It goes to
  `Core/` only when its semantics are genuinely neutral and it has no natural
  subject owner; many imports alone do not make it neutral. When constraint and
  subject disagree, the layout is wrong, not the constraint.
- "This feature has many adapters" is **not** an argument for a vertical slice:
  adapters live in `Infrastructure/` either way.

The following ADR 0022 rules define the accepted target layout. P1-P7 are the
current physical architecture; P8 is not landed:

- A leaf module is a subject with one owner and lifecycle. Internal folders
  follow the subject; do not create an empty role skeleton.
- Add `Contract/` only for exact types used by named external owner-consumers.
  A private leaf has no public surface.
- A port introduced for dependency inversion belongs to its consumer. P3 proves
  only `Analysis\Run\Contract\FileSetInspectionParticipantInterface` plus the
  two P4 capability-specific preparation contracts; do not add a generic
  lifecycle, graph-preparation, or metric-derivation port.
- `Analysis`, `Analysis\Evidence`, and `Analysis\Policy` are navigation
  taxonomies only: no PHP types, state, shared contracts or qmx allow target.
- `Core` holds only neutral primitives without a natural leaf owner. Many
  imports do not make a type neutral.
- `Infrastructure` owns delivery and composition adapters, not application
  policy or capability state.
- Tests follow their owning subject; test level, fixtures and support are
  subdivisions inside that subject rather than top-level role buckets.
- Every production namespace has one explicit leaf owner. Do not use an
  open-ended owner template that silently enrols a future sibling.

The former `Metrics/` and `Rules/` role buckets have been removed. Follow the
manifest-backed current ownership and the migration plan; do not simulate P8
changes before its review gates.

### Adapter-exclusion principle

Adapters (CLI commands, HTTP endpoints, message handlers, shell hooks) live
in `src/Infrastructure/` regardless of which feature they touch. They
depend on the slice through its public service contracts. Example:
`LayerAssignmentCommand` stays at
`src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php` and
injects `LayerAssignmentInspectorInterface` plus Collection services — pulling
the command into the Architecture slice would force the slice to depend on
`symfony/console`, which is an infrastructure concern.

---

## Key Features

### Metrics and Rules
- **Complexity**: Cyclomatic (CCN), Cognitive Complexity, NPATH Complexity
- **Maintainability**: Halstead, Maintainability Index
- **Coupling**: CBO (Coupling Between Objects), Distance from Main Sequence, Instability, Abstractness, ClassRank (PageRank)
- **Cohesion**: TCC/LCC (Tight/Loose Class Cohesion), LCOM4, WMC (Weighted Methods per Class)
- **Size**: LOC, Class Count, Namespace Size, Property Count, Method Count
- **Design**: DIT (Depth of Inheritance Tree), NOC (Number of Children), Type Coverage
- **Architecture**: Layer Policy Enforcement (multi-criterion membership, template layers, `exclude:`, `relations:` whitelist — deptrac replacement), Circular Dependency Detection, Dependency Graph Export (DOT)
- **Code Smell**: Boolean Argument, Debug Code, Empty Catch, eval, exit/die, goto, Superglobals, Error Suppression, Count in Loop, Long Parameter List, Unreachable Code, Identical Sub-expression
- **Security**: Hardcoded Credentials, SQL Injection, XSS, Command Injection, Sensitive Parameter Detection
- **Computed Metrics**: 6 built-in health scores (complexity, cohesion, coupling, design, maintainability, overall), user-definable metrics via Symfony Expression Language formulas, per-level formulas, threshold-based findings

### Infrastructure
- **Parallel Processing**: Multi-worker file processing via amphp/parallel
- **Profiler**: Internal span-based profiler for performance diagnostics
- **Serialization**: Automatic selection of the best serializer (igbinary/PHP serialize)
- **Git Integration**: Analysis of changed files only, staged files
- **Baseline Support**: Ignoring known issues, @qmx-ignore tags
- **Multiple Formats**: Text, JSON, Metrics, Checkstyle, SARIF, GitLab Code Quality, Health
- **Caching**: AST caching for faster repeated runs
- **Progress Reporting**: Progress bar, PSR-3 logging
- **Technical Debt**: Remediation time estimation, debt summary in reports
- **Analysis Presets**: Built-in presets (`--preset=strict|legacy|ci`), composable, custom presets via YAML files
- **Git Hooks**: Automatic pre-commit checks

---

## Backward Compatibility Policy

**Architecture and correctness outweigh backward compatibility.** The project has
no meaningful external user base yet; consumer projects are updated by hand.
Therefore:

- Do **not** design around preserving an existing public contract when a cleaner
  contract is available. Breaking `RuleInterface`, the config schema, the
  baseline file format, or the CLI surface is an acceptable cost, not a last
  resort.
- Do **not** add compatibility shims, deprecation layers, or CLI aliases "just in
  case". Fewer surfaces is the goal — a removed option beats an alias.
- Do **not** cite "this would break extension authors" as an argument against a
  design. There are none. Cite real architectural cost instead.

**The one hard requirement is history.** Every breaking change must be traceable
to *what* changed and *why*, so consumer projects can be updated mechanically:

- `CHANGELOG.md` gets a `Breaking` entry naming the old and the new surface.
- Non-obvious rationale goes into an ADR under `docs/adr/`.
- Migration steps are written from the consumer's perspective, not the
  implementer's.

This policy governs *outward* contracts. It is not a licence to break internal
invariants without tests, nor to skip review.

---

## Metrics Policy

Base metrics must faithfully implement the original academic algorithm.

**Acceptable extensions** (must be documented in code docblocks AND website docs):
- Modern PHP operators absent from the original paper (e.g., `??`, `match`, `?->` for CCN)
- Scope adaptation for PHP realities (e.g., RFC including global functions)
- Graph extensions standard in modern tooling (e.g., LCOM4 method-call edges)

**Not acceptable:**
- Changing the fundamental formula without renaming the metric
- Claiming "follows the original spec" when using a different approach
- Undocumented deviations

When documenting deviations: use `!!! info "Deviation from original spec"` blocks on the website,
`> **Note:**` blocks in component READMEs, and accurate phrasing in docblocks
(e.g., "semantic interpretation of" instead of "follows the original").

---

## Critical Rules

### 1. Dependency Graph (DO NOT VIOLATE!)

- **Target leaf capabilities** would depend only on declared public contracts;
  sibling internals and taxonomy parents are not approved targets for new
  migration grants.
- **Core** contains neutral primitives only and has no project dependencies
  (PHP and php-parser types are allowed).
- **Analysis\Run phase ports** are limited to the P3 FileSet inspection
  participant. Graph preparation and metric derivation remain unapproved ports.
- **Infrastructure** may depend on capabilities for delivery/composition;
  capabilities do not depend on framework adapters.
- The internal manifest is the current exact owner/visibility/import authority.
  Its checker runs through `composer architecture:check` before selfcheck and
  rejects unlisted exact imports even when a coarse qmx owner edge permits them.
- Generated `qmx.yaml` contains one semantic-owner layer per owner, no singleton
  enforcement seams and final `external`; `coverage: error` keeps isolated and
  edge-connected project declarations fail-closed. The qmx graph is coarse and
  does not replace the manifest checker.

### 2. Stateless Rules, Stateful-per-file Collectors

```php
// Correct: Rule reads pre-computed metrics
public function analyze(AnalysisContext $context): array {
    foreach ($context->metrics->allCallables() as $callable) {
        $subject = $callable->subject
            ?? throw new LogicException('Callable metrics require an exact declaration subject');
        $ccn = $context->metrics->getSubject($subject)->get(MetricName::COMPLEXITY_CCN);
    }
}

// Wrong: Rule performs AST traversal
public function analyze(AnalysisContext $context): array {
    $traverser = new NodeTraverser(); // WRONG!
}
```

### 3. Pipeline Phase Separation

```
Discovery -> Collection (parallel) -> Aggregation -> RuleExecution -> Reporting
                |                        |              |               |
             MetricBag[]          AggregatedMetrics  Finding[]        Output
```

- **Discovery** — finding PHP files for analysis
- **Collection** — the only parallelizable phase (85-95% of total time)
- **Aggregation/RuleExecution/Reporting** — sequential, fast
- **Duplication detection** is memory-intensive (stores tokens of all matching files). Automatically skipped when `duplication.code-duplication` rule is disabled via `--disable-rule`. Same for `architecture.circular-dependency`

### 4. SymbolPath for Identification

```php
// Use MetricSubject for exact declaration metrics and findings
$subject = MetricSubject::declaration($declarationPath);

// SymbolPath remains the logical identity for named symbols and aggregates
SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
SymbolPath::forClass('App\Service', 'UserService');
SymbolPath::forNamespace('App\Service');
SymbolPath::forFile('src/Service/UserService.php');

// Do not use string FQNs directly
$repository->forMethod('App\Service\UserService::calculate'); // OLD API
```

### 5. Atomic Cache Writes

```php
// Correct: atomic rename
$tmp = $path . '.tmp.' . getmypid();
file_put_contents($tmp, serialize($data));
rename($tmp, $path);

// Wrong: direct write (race condition)
file_put_contents($path, serialize($data));
```

### 6. Anonymous Classes — Ignore

```php
// In ClassCountCollector:
if ($node instanceof Class_ && $node->name !== null) {
    // named class — count it
}
// anonymous — skip
```

### 7. Symfony DI: Automatic Service Registration

Collectors, formatters, and configuration stages are registered **automatically** via `PhpFileLoader::registerClasses()`.
Standard Symfony practices are used: **autowiring** and **autoconfiguration**.

**How it works:**
1. `registerForAutoconfiguration()` defines tags for interfaces
2. `registerClasses()` scans directories and registers discovered classes
3. Prototype with `setAutoconfigured(true)->setAutowired(true)`:
   - **Autoconfigured** — automatic tagging of interface implementations
   - **Autowired** — automatic dependency resolution via type hints
4. CompilerPasses collect services by tags

**Adding a new collector:**
1. Identify the owning capability and create the collector under its exact
   `src/Analysis/Evidence/{Capability}/` root
2. Implement `MetricCollectorInterface` (or `DerivedCollectorInterface`, `GlobalContextCollectorInterface`)
3. Extend only that capability's exact collector registration in its
   Infrastructure configurator; do not add an open-ended sibling scan

**Adding a new formatter:**
1. Create a `*Formatter.php` class in `src/Reporting/Formatter/`
2. Implement `FormatterInterface`
3. The class will be registered **automatically**

**Adding a new configuration stage:**
1. Create a class in `src/Analysis/Configuration/Pipeline/Stage/`
2. Implement `ConfigurationStageInterface`
3. The class will be registered **automatically** and added to `ConfigurationPipeline`

**Adding a new config option (YAML key):**
1. Add a constant to `src/Analysis/Configuration/ConfigSchema.php` (e.g., `public const MY_OPTION = 'my.option'`)
2. Add an entry to `ConfigSchema::ENTRIES` (source path, result key, root type)
3. Add handling in the owning resolver or adapter (`DefaultsStage`, `CliStage`,
   `RunConfigurationResolver`, or another exact consumer); do not add a
   cross-owner runtime field to Configuration.
4. All consumers must reference the constant, not a string literal

**Adding a new rule:**

Place the rule with its owning subject per ADR 0016 / ADR 0022:

1. Create the `*Rule.php` and its Options class inside the owning capability.
2. Implement the internal executable rule contract (or extend `AbstractRule`)
   and `RuleOptionsInterface` as appropriate.
3. Expose only the Finding-owned `RuleDefinitionInterface` metadata contract
   to cross-owner class-string consumers.
4. Extend only the owning capability configurator's exact rule registration;
   rules are lazy and deliberately not autowired.

**How rule registration works:**
1. `ArchitectureConfigurator`, `CircularDependencyConfigurator`,
   `ComputedMetricsConfigurator`, `DuplicationConfigurator`, and the exact
   `CodeSmell`, `Cohesion`, `Complexity`, `Coupling`, `Design`,
   `Maintainability`, `Security`, and `Size` configurators register only their
   owned rule roots. `RuleConfigurator` retains registry composition but does
   not scan a role bucket.
2. Cross-owner consumers of rule class strings depend only on
   `Analysis\Finding\Contract\Rule\RuleDefinitionInterface`, whose sole
   metadata operation returns the options class.
3. Rule execution, registration, and construction remain Finding and
   Infrastructure internals; do not expose an instance or factory contract.

**Important:** Finding and Infrastructure own executable-rule construction;
cross-owner code must not construct rules or depend on their instances.

**Important:** A capability configurator must enumerate its exact collector and
rule roots. Do not use a wildcard that silently enrols a future evidence
capability.

**Exclude patterns (not registered as services):**
- `Abstract*.php` — abstract classes
- `*Interface.php` — interfaces
- `*Visitor.php` — AST visitors
- `*ClassData.php`, `*Metrics.php`, `*Calculator.php` — auxiliary VOs

**CompilerPasses collect services by tags:**
- `CollectorCompilerPass` -> `CompositeCollector`
- `GlobalCollectorCompilerPass` -> `GlobalCollectorRunner`
- Rule compiler passes -> Finding's private execution and metadata registries
- `FormatterCompilerPass` -> `FormatterRegistry`
- `ConfigurationStageCompilerPass` -> `ConfigurationPipeline`

### 8. Escape `@qmx-*` Tags in Docblocks

When referencing `@qmx-ignore` or `@qmx-threshold` in docblocks as documentation (format descriptions, examples), wrap them in backticks. The parser strips backtick-delimited regions before matching, so unescaped tags in docblocks are interpreted as real suppressions/overrides.

```php
// Wrong: will be parsed as a real suppression tag
/**
 * Use @qmx-ignore complexity to suppress this rule.
 */

// Correct: backtick-escaped, ignored by the parser
/**
 * Use `@qmx-ignore complexity` to suppress this rule.
 */
```

### 9. Test Method Naming: `itXxx` + `#[Test]`

All PHPUnit test methods use the BDD-style `itXxx` naming and the `#[Test]` attribute. The legacy `testXxx` prefix is **not** used.

```php
use PHPUnit\Framework\Attributes\Test;

final class HealthScoreTest extends TestCase
{
    #[Test]
    public function itClampsValuesToTheZeroOneRange(): void
    {
        // ...
    }
}
```

- The `#[Test]` attribute is what PHPUnit relies on for discovery; the `it` prefix is just a convention readers can pattern-match on.
- New tests must follow this convention. Do not introduce `testXxx` methods.
- Data providers and helper methods keep their normal names (e.g., `provideFooCases`, `setUp`) — only test cases use `itXxx`.

### 10. No Private Identifiers or Absolute Home Paths in Tracked Files

This is a **public** repository. Nothing tracked may name a private codebase or
reveal a developer's local filesystem layout — not in code, not in docs, not in
comments, not in benchmark tooling.

```php
// Wrong: names a private project and hardcodes a local path
['id' => 'acme-billing', 'path' => "/Users/<you>/projects/acme-billing/src"],

// Correct: private targets come from git-ignored local config
// benchmarks/local-projects.json (see benchmarks/local-projects.json.example)
```

- Private benchmark targets live in `benchmarks/local-projects.json` and
  `benchmarks/local.env` — both git-ignored, both with committed `.example` files.
- In docs, write "a private production backend", never the real name.

`scripts/check-private-leaks.sh` enforces this. It runs in `composer check` (so
also in CI), in the pre-commit hook, and — for commit messages — in the
commit-msg hook and a dedicated CI job. Three checks:

| Check                                                                                                              | Needs the denylist? |
| ------------------------------------------------------------------------------------------------------------------ | ------------------- |
| Absolute `/Users` / `/home` paths                                                                                  | no                  |
| Private project names                                                                                              | **yes**             |
| Inventory shapes: `'type' => 'proprietary'` with a hardcoded path, markdown tables listing codebases by `~N files` | no                  |

**The denylist is never committed** — the names are exactly what is being
protected. It is read from `$QMX_PRIVATE_TERMS` (newline-separated) or, failing
that, from the git-ignored `scripts/private-terms.local.txt`.

- **Local machine:** create `scripts/private-terms.local.txt`.
- **CI:** set the `QMX_PRIVATE_TERMS` repository secret. Absent, the name check
  is skipped and the run still passes — so a green build does not prove the
  names were checked.
- **Remote/web workspace:** `scripts/init-environment.sh` writes the local file
  from `$QMX_PRIVATE_TERMS` at session start, and warns loudly when unset.

The script never prints matched content — only `file:line`. A matching line can
itself contain the private name, so echoing it would leak it into the build log.

---

## Technology Stack

| Tool                         | Version        | Purpose                  |
| ---------------------------- | -------------- | ------------------------ |
| PHP                          | ^8.4           | Runtime                  |
| nikic/php-parser             | ^5.0           | AST parsing              |
| amphp/parallel               | ^2.0           | Parallel file processing |
| symfony/console              | ^7.4 \|\| ^8.0 | CLI                      |
| symfony/dependency-injection | ^7.4 \|\| ^8.0 | DI container             |
| symfony/yaml                 | ^7.4 \|\| ^8.0 | YAML configuration       |
| symfony/expression-language  | ^7.4 \|\| ^8.0 | Computed metric formulas |
| symfony/finder               | ^7.4 \|\| ^8.0 | File discovery           |
| psr/log                      | ^3.0           | PSR-3 logging            |
| PHPUnit                      | ^12.0          | Tests                    |
| PHPStan                      | ^2.0, level 8  | Static analysis          |
| PHP-CS-Fixer                 | ^3.0           | Code style (PER-CS 2.0)  |

## Essential Commands

```bash
# Project validation
composer check          # everything below, in the order a failure is cheapest to read
composer check:code     # what a code change invalidates: cs-check, phpstan, PHPUnit, cross-tool
composer check:docs     # what a website change invalidates: a strict mkdocs build
composer check:artifacts # what a manifest, config or corpus change invalidates: every generated artifact
composer check:self     # what the product says about this repo: gate self-test + qmx ratchet + directive audit
composer architecture:check # exact manifest policy + generated-artifact freshness
composer docs:check     # mkdocs --strict build of website/ (broken links, nav gaps)
composer test           # PHPUnit
composer phpstan        # PHPStan level 8

# Finding equivalence between two revisions of the product
composer gate -- --reference=<git-ref>           # compare findings; GREEN 0, PARTIAL 2, RED 1, cannot-run 3
composer gate:controls -- --reference=<git-ref>  # prove the gate is red under each planted breakage

# What each inline @qmx directive in a tree still does (--sweep=narrow re-executes only the addressed rule; default)
bin/qmx directives src/                          # 0 clean, 2 an inert directive, 3 bad config, 4 run incomplete
bin/qmx directives src/ --sweep=full             # same verdicts, every enabled rule re-executed instead of one
composer directives:audit                        # bin/qmx directives over src/, part of check:self after selfcheck

# The narrow/full control itself (not part of composer check): three comparisons, each naming its
# target and its config. The seeded fixture whole (every verdict and every refusal, floor enforced),
# its Silenced/ half (where a wrong-producer defect reaches the verdict comparison instead of being
# refused earlier), then src/. Cheapest signal first: composer stops at the first run that fails, so
# the 70-second src/ run last is the difference between seeing the new signal and not.
composer directives:narrow-control                # 0 agreed, 1 disagreed, 2 population too uniform,
                                                  # 3 a run that cannot be compared, 7 an unreadable report

# Proving the threshold audit's own tests bite
composer directives:controls                     # coverage arithmetic first (seconds), then plant one breakage at a
                                                  # time; every case must be reddened by one
composer directives:controls -- --only=<id,...>  # one probe, for iterating (coverage is then not evidence)
composer directives:controls -- --jobs=1         # same verdicts, one worker; re-read a disagreement here first
composer directives:controls:coverage            # the cheap half on its own: removing a declaration must redden
                                                  # exactly its own case, and a declared case the run never carried
                                                  # is a distinct stale-declaration refusal

# HTML report (run when modifying src/Reporting/Template/)
composer test:js        # JS tests for HTML report (vitest)
composer build:js       # Rebuild HTML report JS bundle

# Basic analysis
bin/qmx check src/
bin/qmx check src/ --format=json --workers=0

# Presets
bin/qmx check src/ --preset=strict          # Greenfield: tight thresholds
bin/qmx check src/ --preset=legacy           # Legacy: relaxed thresholds
bin/qmx check src/ --preset=strict,ci        # Combine multiple presets

# Git integration
bin/qmx check src/ --report=git:staged
bin/qmx check src/ --report=git:main..HEAD

# Baseline
bin/qmx check src/ --baseline=baseline.json
bin/qmx baseline:generate baseline.json src/

# Benchmarks (metric calibration against real projects)
cd benchmarks && composer install
php scripts/collect-benchmark-data.php [output-file.json]
composer benchmark:check       # Regression check: health scores vs expected ranges
composer benchmark:update      # Recalibrate baseline ranges after formula changes

# Hooks
bin/qmx hook:install
bin/qmx hook:status

# Full list of options
bin/qmx check --help
```

---

## Workflow

**Before implementation:** read README.md in the corresponding `src/` directory

**Project-specific steps** (in addition to the global workflow):
- **Validation**: `composer check` (cs-check + strict docs build + tests + phpstan + exact manifest/freshness check + coarse qmx selfcheck). A direct `bin/qmx check` is product analysis only and does not run the repository's exact manifest policy. When modifying `src/Reporting/Template/`, also run `composer test:js` and `composer build:js`
- **Documentation**: Update `README.md` in the affected `src/` directory (add new files, fix outdated info). Update website documentation (see [Website Documentation](#website-documentation) section below)

### Efficient validation order

For multi-package changes, fail fast before paying for the full test suite:

1. Run lint/style, focused tests, and scoped static analysis for each package.
   An aggregate PHPStan run that reports errors in files the change never
   touched: delete `.phpstan.cache` and run it again before acting on them. A
   run of this shape has been seen to go green on the second, cold pass, and
   the cause is not known — so treat the cold result as the verdict rather than
   hunting a regression the tree may not carry.
2. Before the root aggregate, run full PHPStan, `composer architecture:check`,
   and dogfood with machine-readable output, `--workers=0`, and
   `--fail-on=warning`.
3. Run `composer check` once before review and once after confirmed review
   fixes. Repeat it earlier only when a change invalidates prior aggregate
   evidence.

`composer check` is four groups plus the leak scan, and each group is named by
what invalidates it, so a change that touched one thing pays for one group:
`check:code` (style, static analysis, tests), `check:docs` (strict mkdocs),
`check:artifacts` (manifest and every generated artifact against a fresh
measurement) and `check:self` (the gate's self-test, the qmx ratchet, and the
inline-directive audit). Sizes
measured on this tree: the tests dominate at ~150s, the suppression snapshot
costs ~20s, and everything else together is under 15s. `architecture:check`
deliberately runs in both `check:artifacts` and — as its first half —
`selfcheck`: the ratchet may not judge a tree whose generated artifacts are
stale. Only the aggregate is evidence for review; a green group is evidence
about that group.

Subagents own focused package gates; the root orchestrator owns full aggregate
gates. For every long-running or redirected command, persist its output under
`/tmp`, wait for completion, and inspect the explicit exit code. Empty or
redirected stdout is never evidence of success.

**Architecture Decision Records:** After implementing a feature with non-obvious design decisions, create an ADR in `docs/adr/` (see [docs/adr/README.md](docs/adr/README.md) for format). If a spec existed during design (`docs/internal/SPEC_*.md`), it can be archived or deleted after the ADR captures key decisions. ADRs preserve the "why" — implementation details live in code and component READMEs.

**Commit granularity:** Split large changes into logical commits when it improves changelog readability. Each commit should represent one coherent change (e.g., separate "rename command" from "update documentation"). Avoid monolithic commits that bundle unrelated changes — they make changelogs harder to generate and git history harder to navigate.

### Proving a rename changed nothing else

Run the gate for any change that renames a channel, a rule, a metric key or a
published finding field, and for any change to how a finding is published.
`composer gate -- --reference=<the commit the change starts from>` checks out that
commit, runs both binaries over the current corpus and compares findings, the
twelve formats, exit codes, `qmx rules`, `baseline:explain`, the generated
baseline and the suppressed report. Corpus, maps, normalization list and
equivalence tuple live in `finding-gate/`; its README holds the case schema and
the surface list.

- Declare every intended rename as a row in `finding-gate/maps/`. An undeclared
  rename is red, and a declared rename that translated nothing is red too.
- Add a channel and its corpus fixture together, in the case that owns its
  family, and name it in that case's `channels`.
- Never point a corpus case at project code. The corpus is external because the
  project analyses itself: a case reading `src/` moves the gate's input with the
  same step it is measuring.
- A GREEN run whose reference has the same product code proves the normalization
  list is complete, not that a step is safe. Proof of a step needs the previous
  step's commit as the reference.
- `PARTIAL` is not evidence of anything: it means `--cases` or
  `--incomplete-corpus` narrowed the run. Do not cite it as green.
- Re-run `composer gate:controls` after changing the comparator itself. A gate
  that proved itself before the rewrite says nothing about the rewritten one.
- `--derive-normalization` and `--derive-tuple` regenerate their tracked files.
  Do not hand-edit either: a row that no measurement produced is a claim about
  nondeterminism that nothing checks. Every `--derive-*` mode is a write, not a
  check: it exits 4 when it wrote and 5 when the measurement it would have
  written from failed, and never 0.

### Self-Analysis: Interpreting Results

Run `bin/qmx check src/` after modifying metric collection or aggregation logic to catch regressions.

**How to interpret findings:**
- **Invariant test failure** (e.g., parent.sum ≠ Σ children): **Bug** — fix immediately, add regression test
- **Golden file test failure after intentional algorithm change**: Update expected values in `tests/Analysis/Evidence/Measurement/Integration/Aggregation/GoldenFileAggregationTest.php` after verifying new values are correct
- **Coupling findings** (high CBO, circular dependencies): **Architecture issue** — evaluate refactoring vs. threshold adjustment
- **Complexity findings** (CCN > threshold): **Code quality signal** — normal for complex algorithms, investigate only if unexpected
- **Health score regression** vs `composer benchmark:check`: May indicate **formula bug** if changes touched computed metrics

### Dogfooding: Finding Management Strategy

We analyze ourselves with `bin/qmx check src/` using `qmx.yaml` and the
versioned root `qmx-baseline.json`. That file is a v11 ratchet snapshot for
residual, currently accepted warnings only; it is not a suppress-mode or legacy
baseline. The generated qmx projection enforces coarse owner/seam topology.
`composer selfcheck` first runs `composer architecture:check`, which validates
the exact manifest policy and generated freshness, and then applies the qmx
ratchet with `--fail-on=warning`. A direct `bin/qmx check` omits that first
repository-governance step.

**Decision framework** (in priority order):

| Situation                                  | Action                                                |
| ------------------------------------------ | ----------------------------------------------------- |
| Real issue                                 | Fix the code                                          |
| Structural (project nature)                | `exclude_paths` or `exclude_namespaces` in `qmx.yaml` |
| Threshold mismatch                         | Tune threshold in `qmx.yaml`                          |
| Legitimate exception for a specific symbol | `@qmx-threshold` on the class/method with reason      |
| Genuinely inapplicable                     | `@qmx-ignore` with reason                             |
| Generated or non-analyzable file           | `@qmx-ignore-file` with reason                        |

**Key principles:**
- **Refactoring is the default response, not threshold tweaking.** When a check signal flags real architectural debt (high WMC, low cohesion, complexity, coupling), prefer extracting a class, splitting responsibilities, or otherwise improving the architecture — that's why we measure. Refactoring cost is low for an AI agent; the metric is the signal. Threshold tweaking is reserved for cases where the metric mis-models the design (e.g., stateless utility classes have low cohesion *by construction*, not as a defect).
- All `@qmx-*` inline tags are available for use — pick the right one for the situation
- Every inline tag must include a reason explaining **why** the exception is acceptable
- Prefer `qmx.yaml` configuration over inline tags when the exclusion applies to a category (e.g., all visitors, all DI configurators)
- Prefer `@qmx-threshold` over `@qmx-ignore` — keeping the rule active with adjusted limits is better than silencing it
- Prefer direct thresholds, scoped configuration exclusions, and justified
  point suppressions over adding findings to the ratchet. Baseline lifecycle
  and recalibration must be explicit and reviewed: regenerate
  `qmx-baseline.json` only after an intentional change to accepted residual
  debt, and review the resulting v11 snapshot diff
- Never use suppress-mode or legacy baselines for dogfooding

---

## Changelog

The project maintains a `CHANGELOG.md` following the [Keep a Changelog](https://keepachangelog.com/) format.

**When to update:** After completing a user-facing change (`feat`, `fix`, or breaking change), add an entry to the `## [Unreleased]` section of `CHANGELOG.md`. Do NOT add entries for `refactor`, `test`, `docs`, or `chore` commits unless they affect user-facing behavior.

**Categories** (use only when relevant, don't create empty sections):
- `Changed` — new features and modifications (combines "Added" and "Changed")
- `Fixed` — bug fixes
- `Deprecated` / `Removed` — lifecycle changes
- `Breaking` — backward-incompatible changes

**Style:**
- Write from the user's perspective: "`exclude_paths` option for finding suppression" not "Implemented ExcludePathFilter class"
- Aggregate related commits into a single entry
- Keep entries concise (one line each)

**When releasing** (tagging a new version):
1. Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`
2. Add a fresh empty `## [Unreleased]` section above it
3. Update the comparison links at the bottom of the file
4. Commit: `chore: release vX.Y.Z`
5. Tag and push: `git tag vX.Y.Z && git push origin vX.Y.Z`
6. CI workflow (`.github/workflows/release.yml`) creates a GitHub Release automatically, extracting notes from CHANGELOG
7. Monitor CI runs on the tag to confirm all checks pass — the release should be green

---

## Website Documentation

When modifying any user-facing functionality, update the corresponding website documentation.
See [website/CONTRIBUTING_DOCS.md](website/CONTRIBUTING_DOCS.md) for the full mapping table and structure guidelines.

Key rules:
- Update both EN (`.md`) and RU (`.ru.md`) versions simultaneously
- Follow the canonical page structure defined in the guide
- When changing a metric algorithm, add/update the "Implementation notes" section
- Keep `website/docs/reference/default-thresholds.md` in sync with actual defaults
- After any documentation changes, verify the site builds without errors or warnings:
  ```bash
  composer docs:check
  ```
  It runs `mkdocs build --strict` into a temporary directory and fails on broken
  links, pages missing from the nav and any other warning. The interpreter comes
  from `$QMX_MKDOCS`, then `website/.venv/bin/mkdocs`, then `PATH`; when none
  works it fails with setup instructions instead of skipping. First-time setup:
  ```bash
  python3 -m venv website/.venv && website/.venv/bin/pip install -r website/requirements.txt
  ```
  The same check runs inside `composer check` (so also in CI) and in the deploy
  workflow, which builds strictly before publishing.

---

## Related Documents

### Component Documentation (in src/)
- [src/Core/README.md](src/Core/README.md) — contracts and primitives
- [src/Analysis/Policy/Architecture/README.md](src/Analysis/Policy/Architecture/README.md) — declared-layer policy capability
- [src/Analysis/Evidence/CircularDependency/README.md](src/Analysis/Evidence/CircularDependency/README.md) — circular-dependency evidence capability
- [src/Analysis/Evidence/Duplication/README.md](src/Analysis/Evidence/Duplication/README.md) — Duplication capability boundary, lifecycle, Run-port integration and tests
- [src/Analysis/Evidence/CodeSmell/README.md](src/Analysis/Evidence/CodeSmell/README.md) — code-smell evidence and rules
- [src/Analysis/Evidence/Cohesion/README.md](src/Analysis/Evidence/Cohesion/README.md) — cohesion evidence and rules
- [src/Analysis/Evidence/Complexity/README.md](src/Analysis/Evidence/Complexity/README.md) — complexity evidence and rules
- [src/Analysis/Evidence/Coupling/README.md](src/Analysis/Evidence/Coupling/README.md) — coupling evidence, rules, and configuration
- [src/Analysis/Evidence/Design/README.md](src/Analysis/Evidence/Design/README.md) — design evidence and rules
- [src/Analysis/Evidence/Maintainability/README.md](src/Analysis/Evidence/Maintainability/README.md) — maintainability evidence and rules
- [src/Analysis/Evidence/Security/README.md](src/Analysis/Evidence/Security/README.md) — security evidence and rules
- [src/Analysis/Evidence/Size/README.md](src/Analysis/Evidence/Size/README.md) — size evidence and rules
- [src/Analysis/README.md](src/Analysis/README.md) — orchestration
- [src/Reporting/README.md](src/Reporting/README.md) — formatting
- [src/Analysis/Configuration/README.md](src/Analysis/Configuration/README.md) — configuration
- [src/Analysis/Policy/Baseline/README.md](src/Analysis/Policy/Baseline/README.md) — baseline persistence and acceptance policy
- [src/Analysis/Policy/Inline/README.md](src/Analysis/Policy/Inline/README.md) — `@qmx-ignore` suppression and inline controls
- [src/Infrastructure/README.md](src/Infrastructure/README.md) — CLI, DI, caching
- [finding-gate/README.md](finding-gate/README.md) — the finding-equivalence gate: corpus case schema, compared surfaces, maps, normalization

### Architecture Decision Records (in docs/adr/)
- [docs/adr/README.md](docs/adr/README.md) — ADR format and index

### Internal Documentation (in docs/internal/)
- [docs/internal/PRODUCT_VISION.md](docs/internal/PRODUCT_VISION.md) — target users, key questions, design principles
- [docs/internal/CLI_CONVENTIONS.md](docs/internal/CLI_CONVENTIONS.md) — CLI naming conventions
- [docs/internal/PRODUCT_ROADMAP.md](docs/internal/PRODUCT_ROADMAP.md) — feature roadmap and priorities
- [docs/internal/COMPETITOR_COMPARISON.md](docs/internal/COMPETITOR_COMPARISON.md) — comparison with PHPMD, PHPStan, etc.

### General Documentation (in docs/)
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — overall architecture
- [website/docs/getting-started/quick-start.md](website/docs/getting-started/quick-start.md) — quick start
- [website/docs/ci-cd/github-actions.md](website/docs/ci-cd/github-actions.md) — GitHub Action integration
