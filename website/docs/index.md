# Qualimetrix

**Fast PHP static analysis tool** focused on code quality metrics, complexity analysis, and architectural insights.

---

## Why Qualimetrix?

- **Fast** — 9x faster than phpmd in sequential mode, up to 39x with parallel workers
- **Deep OOP metrics** — Cyclomatic & Cognitive Complexity, LCOM4, TCC/LCC, RFC, Halstead, Maintainability Index
- **Modern PHP** — built for PHP 8.4, leveraging php-parser v5
- **CI/CD ready** — text, JSON, Checkstyle, SARIF, and GitLab Code Quality output formats
- **Baseline support** — ignore known issues, focus on new code quality
- **Git integration** — analyze only changed or staged files

## Quick Example

```bash
# Install
composer require --dev qualimetrix/qualimetrix

# Analyze your code
vendor/bin/qmx check src/

# Use with git pre-commit hook
vendor/bin/qmx hook:install

# Report only violations from changed files
vendor/bin/qmx check src/ --report=git:main..HEAD
```

## Available Metrics

| Category            | Metrics                                                           |
| ------------------- | ----------------------------------------------------------------- |
| **Complexity**      | Cyclomatic (CCN), Cognitive Complexity, NPATH Complexity          |
| **Maintainability** | Halstead metrics, Maintainability Index                           |
| **Coupling**        | CBO (RFC), Instability, Abstractness, Distance from Main Sequence |
| **Cohesion**        | TCC/LCC, LCOM4, WMC                                               |
| **Size**            | LOC, Method Count, Class Count, Property Count                    |
| **Structure**       | DIT (Depth of Inheritance), NOC (Number of Children)              |
| **Architecture**    | Circular Dependency Detection                                     |
| **Code Smells**     | Boolean arguments, eval, goto, debug code, empty catch, and more  |

## Getting Started

Head to the [Quick Start](getting-started/quick-start.md) guide to integrate Qualimetrix into your project in minutes.

## For AI Agents

If you are an AI coding agent, see [llms.txt](llms.txt) for a concise machine-readable overview, or [llms-full.txt](https://qualimetrix.github.io/qualimetrix/llms-full.txt) for the complete reference in a single file.
