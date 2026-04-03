# Qualimetrix

Static analysis tool for PHP code quality metrics.

**[Documentation](https://qualimetrix.github.io/qualimetrix/)** | [Quick Start](https://qualimetrix.github.io/qualimetrix/getting-started/quick-start/) | [llms.txt](https://qualimetrix.github.io/qualimetrix/llms.txt)

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
composer require --dev qualimetrix/qualimetrix

# Analyze
bin/qmx check src/

# With specific format
bin/qmx check src/ --format=json

# Pre-commit hook
bin/qmx hook:install
```

## Output Formats

| Format       | Use Case                 |
| ------------ | ------------------------ |
| `text`       | CLI, human-readable      |
| `json`       | CI/CD integration        |
| `checkstyle` | Jenkins, SonarQube       |
| `sarif`      | GitHub Security, VS Code |
| `gitlab`     | GitLab Code Quality      |

## Configuration

Create `qmx.yaml` ([full format](https://qualimetrix.github.io/qualimetrix/getting-started/configuration/)):

```yaml
rules:
  complexity.cyclomatic:
    method:
      warning: 15
      error: 25
```

Or use CLI options:

```bash
bin/qmx check src/ --cyclomatic-warning=15 --cyclomatic-error=25
```

## Git Integration

```bash
# Show violations in staged files only
bin/qmx check src/ --report=git:staged

# Show violations in changed files
bin/qmx check src/ --report=git:main..HEAD
```

## Baseline Support

```bash
# Generate baseline for existing violations
bin/qmx check src/ --generate-baseline=baseline.json

# Use baseline
bin/qmx check src/ --baseline=baseline.json
```

## Documentation

- [Quick Start](https://qualimetrix.github.io/qualimetrix/getting-started/quick-start/)
- [Architecture](docs/ARCHITECTURE.md)
- [GitHub Action](https://qualimetrix.github.io/qualimetrix/ci-cd/github-actions/)
- [Changelog](CHANGELOG.md)
- [llms.txt](https://qualimetrix.github.io/qualimetrix/llms.txt) — concise reference for AI agents
- [llms-full.txt](https://qualimetrix.github.io/qualimetrix/llms-full.txt) — complete documentation in a single file

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
