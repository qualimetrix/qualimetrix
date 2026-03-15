# Documentation Index — AI Mess Detector

Complete documentation for the PHP static analysis tool. Choose the relevant category for quick information lookup.

---

## Getting Started

Start here if you are new to the project or want to quickly integrate the tool.

- **[Quick Start](../website/docs/getting-started/quick-start.md)** — three ways to quickly integrate (pre-commit hook, GitHub Action, Docker)
- **[GitHub Action Integration](../website/docs/ci-cd/github-actions.md)** — detailed guide for CI/CD pipeline integration
- **[Documentation website](https://fractalizer.github.io/ai-mess-detector/)** — user-facing documentation

---

## Architecture & Design

Understand the internals and design principles of AI Mess Detector.

- **[Architecture Overview](ARCHITECTURE.md)** — overall architecture, dependency graph, layer separation
- **[Core Primitives](../src/Core/README.md)** — contracts, Value Objects, and Enums (Severity, SymbolPath, Violation, MetricBag, etc.)

---

## Components

Documentation for individual components of the analysis system.

- **[Metric Collectors](../src/Metrics/README.md)** — metric collectors (Cyclomatic Complexity, LOC, Class Count, etc.)
- **[Analysis Rules](../src/Rules/README.md)** — analysis rules and metric interpretation (ComplexityRule, SizeRule, etc.)
- **[Analysis Orchestration](../src/Analysis/README.md)** — four-phase pipeline (Collection -> Aggregation -> Analysis -> Reporting)
- **[Reporting](../src/Reporting/README.md)** — output formatting (Text, JSON, Checkstyle formats)

---

## Configuration & Infrastructure

Tool configuration, CLI interface, DI container, and caching.

- **[Configuration](../src/Configuration/README.md)** — configuration management system (YAML, defaults, CLI options)
- **[Infrastructure](../src/Infrastructure/README.md)** — CLI (Symfony Console), DI container, PHP parser, progress reporting, caching

---

## For AI Agents

- **[CLAUDE.md](../CLAUDE.md)** — required guide for AI agents (working rules, dependency graph, implementation order)

---

---

## Quick Navigation

**I am creating a new component:**
1. Read [CLAUDE.md](../CLAUDE.md) to understand the rules
2. Read [ARCHITECTURE.md](ARCHITECTURE.md) for context
3. Choose the relevant component above and read the corresponding document

**I want to integrate the tool:**
-> [Quick Start](../website/docs/getting-started/quick-start.md) or [GitHub Action](../website/docs/ci-cd/github-actions.md)

**I want to understand the entire system:**
-> [Architecture Overview](ARCHITECTURE.md) -> [Core Primitives](../src/Core/README.md)

---

## Key Concepts

Quick reference for key concepts:

| Term                | Description                                                            |
| ------------------- | ---------------------------------------------------------------------- |
| **SymbolPath**      | Unique code identifier (method, class, namespace, or file)             |
| **MetricBag**       | Container for collected metrics from a single file                     |
| **MetricCollector** | Component that collects metrics from AST (stateful per-file)           |
| **Rule**            | Component that interprets metrics and generates violations (stateless) |
| **Violation**       | Detected rule violation with severity, location, and message           |
| **AnalysisContext** | Analysis context containing metrics and the dependency graph           |
| **Pipeline**        | Four-phase process: Collection -> Aggregation -> Analysis -> Reporting |

---

## Technology Stack

- **PHP:** ^8.4
- **Parser:** nikic/php-parser ^5.0
- **DI Container:** symfony/dependency-injection ^7.4 || ^8.0
- **Console:** symfony/console ^7.4 || ^8.0
- **Testing:** PHPUnit ^12.0
- **Static Analysis:** PHPStan ^2.0 (level 8)
- **Code Style:** PHP-CS-Fixer ^3.0 (PER-CS 2.0)
- **Architecture:** Deptrac ^2.0

---

## Essential Commands

```bash
# Install dependencies
composer install

# Run tests
composer test                 # Run PHPUnit
composer test:coverage        # With coverage report

# Static analysis
composer phpstan              # Run PHPStan level 8
composer deptrac              # Check architecture layers
composer cs-fix               # Fix code style

# Full validation
composer check                # tests + phpstan + deptrac + cs-fix

# Analyze your code
bin/aimd check src/         # Run analysis
bin/aimd check src/ --help  # See all options
```

---

## Contributing

Before starting work, make sure you have read:
1. [CLAUDE.md](../CLAUDE.md) — rules for developer agents
2. [ARCHITECTURE.md](ARCHITECTURE.md) — project architecture
3. The corresponding component document from this index

Follow the process:
1. `composer check` after each implementation step
2. Commit with a type (`feat`, `fix`, `test`, `refactor`, `docs`, `chore`)
3. Create pull requests to the main branch

---

**Last updated:** 2026-03-05
