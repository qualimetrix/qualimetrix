# AI Mess Detector

Static analysis tool for PHP code quality metrics.

## Features

- **Complexity Metrics**: Cyclomatic (CCN), Cognitive, NPATH
- **Maintainability**: Halstead metrics, Maintainability Index
- **Coupling**: RFC, Instability, Abstractness, Distance from Main Sequence
- **Cohesion**: TCC/LCC, LCOM4, WMC
- **Size**: LOC, Class/Method/Property Count
- **Structure**: DIT, NOC
- **Architecture**: Circular Dependency Detection

## Quick Start

```bash
# Install
composer require --dev fractalizer/ai-mess-detector

# Analyze
bin/aimd analyze src/

# With specific format
bin/aimd analyze src/ --format=json

# Pre-commit hook
bin/aimd hook:install
```

## Output Formats

| Format | Use Case |
|--------|----------|
| `text` | CLI, human-readable |
| `json` | CI/CD integration |
| `checkstyle` | Jenkins, SonarQube |
| `sarif` | GitHub Security, VS Code |
| `gitlab` | GitLab Code Quality |

## Configuration

Create `aimd.yaml`:

```yaml
rules:
  complexity:
    warning_threshold: 10
    error_threshold: 20

  cognitive:
    warning_threshold: 15
    error_threshold: 30
```

Or use CLI options:

```bash
bin/aimd analyze src/ --cc-warning=10 --cc-error=20
```

## Git Integration

```bash
# Analyze staged files only
bin/aimd analyze src/ --staged

# Show violations in changed files
bin/aimd analyze src/ --diff=main
```

## Baseline Support

```bash
# Generate baseline for existing violations
bin/aimd analyze src/ --generate-baseline=baseline.json

# Use baseline
bin/aimd analyze src/ --baseline=baseline.json
```

## Documentation

- [Quick Start](docs/QUICK_START.md)
- [Architecture](docs/ARCHITECTURE.md)
- [GitHub Action](docs/GITHUB_ACTION.md)
- [Benchmarks](docs/BENCHMARKS.md)

## Requirements

- PHP 8.4+
- Composer

## Development

```bash
composer install
composer test      # Run tests
composer phpstan   # Static analysis
composer check     # Full validation
```

## License

MIT
