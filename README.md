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
bin/aimd check src/

# With specific format
bin/aimd check src/ --format=json

# Pre-commit hook
bin/aimd hook:install
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
bin/aimd check src/ --cyclomatic-warning=10 --cyclomatic-error=20
```

## Git Integration

```bash
# Analyze staged files only
bin/aimd check src/ --analyze=git:staged

# Show violations in changed files
bin/aimd check src/ --report=git:main..HEAD
```

## Baseline Support

```bash
# Generate baseline for existing violations
bin/aimd check src/ --generate-baseline=baseline.json

# Use baseline
bin/aimd check src/ --baseline=baseline.json
```

## Documentation

- [Quick Start](docs/QUICK_START.md)
- [Architecture](docs/ARCHITECTURE.md)
- [GitHub Action](docs/GITHUB_ACTION.md)
- [Changelog](CHANGELOG.md)
- [llms.txt](https://fractalizer.github.io/ai-mess-detector/llms.txt) — concise reference for AI agents
- [llms-full.txt](https://fractalizer.github.io/ai-mess-detector/llms-full.txt) — complete documentation in a single file

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
