# AI Mess Detector

**Fast PHP static analysis tool** focused on code quality metrics, complexity analysis, and architectural insights.

---

## Why AI Mess Detector?

- **Fast** — 9x faster than phpmd in sequential mode, up to 39x with parallel workers
- **Deep OOP metrics** — Cyclomatic & Cognitive Complexity, LCOM4, TCC/LCC, RFC, Halstead, Maintainability Index
- **Modern PHP** — built for PHP 8.4, leveraging php-parser v5
- **CI/CD ready** — text, JSON, Checkstyle, SARIF, and GitLab Code Quality output formats
- **Baseline support** — ignore known issues, focus on new code quality
- **Git integration** — analyze only changed or staged files

## Quick Example

```bash
# Install
composer require --dev fractalizer/ai-mess-detector

# Analyze your code
vendor/bin/aimd analyze src/

# Use with git pre-commit hook
vendor/bin/aimd hook:install

# Analyze only staged files
vendor/bin/aimd analyze src/ --staged
```

## Available Metrics

| Category | Metrics |
|----------|---------|
| **Complexity** | Cyclomatic (CCN), Cognitive Complexity, NPATH Complexity |
| **Maintainability** | Halstead metrics, Maintainability Index |
| **Coupling** | CBO (RFC), Instability, Abstractness, Distance from Main Sequence |
| **Cohesion** | TCC/LCC, LCOM4, WMC |
| **Size** | LOC, Method Count, Class Count, Property Count |
| **Structure** | DIT (Depth of Inheritance), NOC (Number of Children) |
| **Architecture** | Circular Dependency Detection |
| **Code Smells** | Boolean arguments, eval, goto, debug code, empty catch, and more |

## Getting Started

Head to the [Quick Start](getting-started/quick-start.md) guide to integrate AI Mess Detector into your project in minutes.
