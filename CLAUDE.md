# CLAUDE.md — Guide for AI Agents

**AI Mess Detector** — a CLI tool for static analysis of PHP code

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
1. This file (CLAUDE.md) — working rules
2. [ARCHITECTURE.md](docs/ARCHITECTURE.md) — understanding the architecture
3. README.md in the corresponding `src/` directory for the current task

**Before implementing a component:**
- Read README.md in the corresponding `src/` directory
- Check the Definition of Done at the end of the document
- Study the related interfaces in `src/Core/README.md`

**After implementing a component:**
- Update README.md in the affected `src/` directory: add new files/classes to the structure diagram, update descriptions
- If default thresholds changed, update the defaults table in `src/Rules/README.md`

**Before adding CLI commands or options:**
- Read [docs/internal/CLI_CONVENTIONS.md](docs/internal/CLI_CONVENTIONS.md) — naming rules

**Before updating website documentation:**
- Read [website/CONTRIBUTING_DOCS.md](website/CONTRIBUTING_DOCS.md) — structure and style rules

---

## Project Structure

```
src/
├── Core/              # Contracts and primitives (no dependencies)
├── Metrics/           # Metric collectors (by category subdirs)
├── Rules/             # Analysis rules (by category subdirs)
├── Baseline/          # Baseline support and @aimd-ignore suppression
├── Analysis/          # Pipeline orchestration, collection, aggregation
├── Reporting/         # Output formatters
├── Configuration/     # YAML config loading
└── Infrastructure/    # CLI, DI, cache, git, profiler
```

Each domain has its own `README.md` with detailed structure, classes, and contracts.

---

## Key Features

### Metrics and Rules
- **Complexity**: Cyclomatic (CCN), Cognitive Complexity, NPATH Complexity
- **Maintainability**: Halstead, Maintainability Index
- **Coupling**: CBO (Coupling Between Objects), Distance from Main Sequence, Instability, Abstractness, ClassRank (PageRank)
- **Cohesion**: TCC/LCC (Tight/Loose Class Cohesion), LCOM4, WMC (Weighted Methods per Class)
- **Size**: LOC, Class Count, Namespace Size, Property Count, Method Count
- **Design**: DIT (Depth of Inheritance Tree), NOC (Number of Children), Type Coverage
- **Architecture**: Circular Dependency Detection, Dependency Graph Export (DOT)
- **Code Smell**: Boolean Argument, Debug Code, Empty Catch, eval, exit/die, goto, Superglobals, Error Suppression, Count in Loop, Long Parameter List, Unreachable Code, Identical Sub-expression
- **Security**: Hardcoded Credentials, SQL Injection, XSS, Command Injection, Sensitive Parameter Detection
- **Computed Metrics**: 6 built-in health scores (complexity, cohesion, coupling, design, maintainability, overall), user-definable metrics via Symfony Expression Language formulas, per-level formulas, threshold-based violations

### Infrastructure
- **Parallel Processing**: Multi-worker file processing via amphp/parallel
- **Profiler**: Internal span-based profiler for performance diagnostics
- **Serialization**: Automatic selection of the best serializer (igbinary/PHP serialize)
- **Git Integration**: Analysis of changed files only, staged files
- **Baseline Support**: Ignoring known issues, @aimd-ignore tags
- **Multiple Formats**: Text, JSON, Metrics JSON, Checkstyle, SARIF, GitLab Code Quality
- **Caching**: AST caching for faster repeated runs
- **Progress Reporting**: Progress bar, PSR-3 logging
- **Technical Debt**: Remediation time estimation, debt summary in reports
- **Git Hooks**: Automatic pre-commit checks

---

## Critical Rules

### 1. Dependency Graph (DO NOT VIOLATE!)

```
Infrastructure -> Analysis -> Metrics/Rules/Reporting/Configuration -> Core
```

- **Core** has no dependencies (only PHP + php-parser types)
- **Infrastructure** depends on all domains
- Dependencies flow DOWNWARD only

### 2. Stateless Rules, Stateful-per-file Collectors

```php
// Correct: Rule reads pre-computed metrics
public function analyze(AnalysisContext $context): array {
    foreach ($context->metrics->all(SymbolType::Method) as $method) {
        $ccn = $context->metrics->get($method->symbolPath);
    }
}

// Wrong: Rule performs AST traversal
public function analyze(AnalysisContext $context): array {
    $traverser = new NodeTraverser(); // WRONG!
}
```

### 3. Pipeline Phase Separation

```
Collection (parallel) -> Aggregation -> Analysis -> Reporting
     |                      |            |           |
  MetricBag[]        AggregatedMetrics  Violation[]  Output
```

- **Collection** — the only parallelizable phase (85-95% of total time)
- **Aggregation/Analysis/Reporting** — sequential, fast
- **Duplication detection** is memory-intensive (stores tokens of all matching files). Automatically skipped when `duplication.code-duplication` rule is disabled via `--disable-rule`. Same for `architecture.circular-dependency`

### 4. SymbolPath for Identification

```php
// Use SymbolPath for violations and metrics
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
1. Create a class in `src/Metrics/{Category}/` (e.g., `src/Metrics/Complexity/`)
2. Implement `MetricCollectorInterface` (or `DerivedCollectorInterface`, `GlobalContextCollectorInterface`)
3. The class will be registered **automatically** — NO need to modify `ContainerFactory`

**Adding a new formatter:**
1. Create a `*Formatter.php` class in `src/Reporting/Formatter/`
2. Implement `FormatterInterface`
3. The class will be registered **automatically**

**Adding a new configuration stage:**
1. Create a class in `src/Configuration/Pipeline/Stage/`
2. Implement `ConfigurationStageInterface`
3. The class will be registered **automatically** and added to `ConfigurationPipeline`

**Adding a new rule:**
1. Create a `*Rule.php` class in `src/Rules/{Category}/` (e.g., `src/Rules/Complexity/`)
2. Implement `RuleInterface` (or extend `AbstractRule`)
3. Add a `NAME` constant with the rule slug in `group.rule-name` format (e.g., `'complexity.cyclomatic'`)
4. Add a static `getOptionsClass()` method returning the Options class
5. Create an Options class in the same directory, implementing `RuleOptionsInterface`
6. The class will be registered **automatically** — NO need to modify `ContainerFactory`

**How rule registration works:**
1. `registerClasses()` scans `src/Rules/**/*Rule.php`
2. `registerForAutoconfiguration(RuleInterface::class)` adds the `aimd.rule` tag
3. `RuleOptionsCompilerPass` automatically registers Options via `RuleOptionsFactory::create()`
4. `RuleCompilerPass` collects all rules into `RuleExecutor`

**Important:** Rules do NOT use autowiring for the constructor (due to `RuleOptionsInterface`). The `$options` argument is injected via `RuleOptionsCompilerPass`.

**Important:** Collectors must be placed in subdirectories `src/Metrics/{Category}/`; files in the root of `src/Metrics/` (except base classes) are ignored.

**Exclude patterns (not registered as services):**
- `Abstract*.php` — abstract classes
- `*Interface.php` — interfaces
- `*Visitor.php` — AST visitors
- `*ClassData.php`, `*Metrics.php`, `*Calculator.php` — auxiliary VOs

**CompilerPasses collect services by tags:**
- `CollectorCompilerPass` -> `CompositeCollector`
- `GlobalCollectorCompilerPass` -> `GlobalCollectorRunner`
- `RuleOptionsCompilerPass` -> registers Options for rules
- `RuleCompilerPass` -> `RuleExecutor::$rules`
- `RuleRegistryCompilerPass` -> `RuleRegistry::$ruleClasses`
- `FormatterCompilerPass` -> `FormatterRegistry`
- `ConfigurationStageCompilerPass` -> `ConfigurationPipeline`

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
| Deptrac                      | ^2.0           | Architecture layers      |

## Essential Commands

```bash
# Project validation
composer check          # tests + phpstan + deptrac
composer test           # PHPUnit
composer phpstan        # PHPStan level 8

# HTML report (run when modifying src/Reporting/Template/)
composer test:js        # JS tests for HTML report (vitest)
composer build:js       # Rebuild HTML report JS bundle

# Basic analysis
bin/aimd check src/
bin/aimd check src/ --format=json --workers=0

# Git integration
bin/aimd check src/ --analyze=git:staged
bin/aimd check src/ --report=git:main..HEAD

# Baseline
bin/aimd check src/ --baseline=baseline.json
bin/aimd check src/ --generate-baseline=baseline.json

# Hooks
bin/aimd hook:install
bin/aimd hook:status

# Full list of options
bin/aimd check --help
```

---

## Workflow

**Before implementation:** read README.md in the corresponding `src/` directory

**Project-specific steps** (in addition to the global workflow):
- **Validation**: `composer check` (tests + phpstan + deptrac). When modifying `src/Reporting/Template/`, also run `composer test:js` and `composer build:js`
- **Documentation**: Update `README.md` in the affected `src/` directory (add new files, fix outdated info). Update website documentation (see [Website Documentation](#website-documentation) section below)

**Architecture Decision Records:** After implementing a feature with non-obvious design decisions, create an ADR in `docs/adr/` (see [docs/adr/README.md](docs/adr/README.md) for format). If a spec existed during design (`docs/internal/SPEC_*.md`), it can be archived or deleted after the ADR captures key decisions. ADRs preserve the "why" — implementation details live in code and component READMEs.

**Commit granularity:** Split large changes into logical commits when it improves changelog readability. Each commit should represent one coherent change (e.g., separate "rename command" from "update documentation"). Avoid monolithic commits that bundle unrelated changes — they make changelogs harder to generate and git history harder to navigate.

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
- Write from the user's perspective: "`exclude_paths` option for violation suppression" not "Implemented ExcludePathFilter class"
- Aggregate related commits into a single entry
- Keep entries concise (one line each)

**When releasing** (tagging a new version):
1. Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`
2. Add a fresh empty `## [Unreleased]` section above it
3. Update the comparison links at the bottom of the file

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
  # If .venv exists (local development):
  cd website && .venv/bin/mkdocs build --strict
  # Otherwise (CI / fresh clone):
  cd website && pip install -r requirements.txt && mkdocs build --strict
  ```

---

## Related Documents

### Component Documentation (in src/)
- [src/Core/README.md](src/Core/README.md) — contracts and primitives
- [src/Metrics/README.md](src/Metrics/README.md) — metric collectors
- [src/Rules/README.md](src/Rules/README.md) — analysis rules
- [src/Analysis/README.md](src/Analysis/README.md) — orchestration
- [src/Reporting/README.md](src/Reporting/README.md) — formatting
- [src/Configuration/README.md](src/Configuration/README.md) — configuration
- [src/Infrastructure/README.md](src/Infrastructure/README.md) — CLI, DI, caching

### Architecture Decision Records (in docs/adr/)
- [docs/adr/README.md](docs/adr/README.md) — ADR format and index

### Internal Documentation (in docs/internal/)
- [docs/internal/CLI_CONVENTIONS.md](docs/internal/CLI_CONVENTIONS.md) — CLI naming conventions

### General Documentation (in docs/)
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — overall architecture
- [website/docs/getting-started/quick-start.md](website/docs/getting-started/quick-start.md) — quick start
- [website/docs/ci-cd/github-actions.md](website/docs/ci-cd/github-actions.md) — GitHub Action integration
