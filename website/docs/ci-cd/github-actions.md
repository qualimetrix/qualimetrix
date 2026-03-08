# GitHub Actions Integration

The AI Mess Detector provides a GitHub Action for easy integration into your CI/CD pipelines.

## Quick Start

```yaml
# .github/workflows/quality.yml
name: Code Quality

on: [push, pull_request]

jobs:
  aimd:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run AI Mess Detector
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
          baseline: 'baseline.json'
```

## Inputs

| Input | Description | Required | Default |
|-------|-------------|----------|---------|
| `paths` | Paths to analyze (space-separated) | No | `src/` |
| `baseline` | Path to baseline file | No | - |
| `config` | Path to config file | No | - |
| `format` | Output format: `text`, `json`, `sarif`, `gitlab` | No | `text` |
| `php-version` | PHP version to use | No | `8.4` |
| `working-directory` | Working directory for analysis | No | `.` |

## Outputs

| Output | Description |
|--------|-------------|
| `violations` | Number of violations found |
| `exit-code` | Exit code (0 = success, 1 = violations found) |

## Examples

### With Baseline

```yaml
- name: Run AI Mess Detector
  uses: fractalizer/ai-mess-detector@v1
  with:
    paths: 'src/'
    baseline: 'baseline.json'
```

### Multiple Paths

```yaml
- name: Run AI Mess Detector
  uses: fractalizer/ai-mess-detector@v1
  with:
    paths: 'src/ lib/ app/'
    config: 'aimd.yaml'
```

### SARIF Output for GitHub Security Tab

```yaml
jobs:
  aimd:
    runs-on: ubuntu-latest
    permissions:
      security-events: write
      contents: read

    steps:
      - uses: actions/checkout@v4

      - name: Run AI Mess Detector
        id: aimd
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
          format: 'sarif'
        continue-on-error: true

      - name: Upload SARIF to GitHub Security
        uses: github/codeql-action/upload-sarif@v3
        if: always()
        with:
          sarif_file: results.sarif
          category: aimd

      - name: Fail if violations found
        if: steps.aimd.outputs.exit-code != '0'
        run: exit ${{ steps.aimd.outputs.exit-code }}
```

### JSON Output with Artifacts

```yaml
- name: Run AI Mess Detector
  uses: fractalizer/ai-mess-detector@v1
  with:
    paths: 'src/'
    format: 'json'

- name: Upload results
  if: always()
  uses: actions/upload-artifact@v4
  with:
    name: aimd-results
    path: aimd-results.json
```

### Using Outputs

```yaml
- name: Run AI Mess Detector
  id: aimd
  uses: fractalizer/ai-mess-detector@v1
  with:
    paths: 'src/'
  continue-on-error: true

- name: Comment on PR
  if: github.event_name == 'pull_request'
  uses: actions/github-script@v7
  with:
    script: |
      github.rest.issues.createComment({
        issue_number: context.issue.number,
        owner: context.repo.owner,
        repo: context.repo.repo,
        body: `## AI Mess Detector Results\n\n` +
              `Violations found: ${{ steps.aimd.outputs.violations }}\n` +
              `Exit code: ${{ steps.aimd.outputs.exit-code }}`
      })
```

### Matrix Testing

```yaml
jobs:
  aimd:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php-version: ['8.3', '8.4']

    steps:
      - uses: actions/checkout@v4

      - name: Run AI Mess Detector
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
          php-version: ${{ matrix.php-version }}
```

## Complete Workflow Example

```yaml
name: Code Quality

on:
  push:
    branches: [main, master, develop]
  pull_request:
    branches: [main, master, develop]

jobs:
  aimd-basic:
    name: AI Mess Detector
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run AI Mess Detector
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
          baseline: 'baseline.json'
          format: 'text'

  aimd-sarif:
    name: AI Mess Detector (SARIF)
    runs-on: ubuntu-latest
    permissions:
      security-events: write
      contents: read
    steps:
      - uses: actions/checkout@v4

      - name: Run AI Mess Detector
        id: aimd
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
          baseline: 'baseline.json'
          format: 'sarif'
        continue-on-error: true

      - name: Upload SARIF results
        uses: github/codeql-action/upload-sarif@v3
        if: always()
        with:
          sarif_file: results.sarif
          category: aimd

      - name: Fail if violations found
        if: steps.aimd.outputs.exit-code != '0'
        run: exit ${{ steps.aimd.outputs.exit-code }}
```

## Integration with Other Tools

### With PHPStan

```yaml
jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install dependencies
        run: composer install

      - name: Run PHPStan
        run: vendor/bin/phpstan analyse

      - name: Run AI Mess Detector
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
```

## Troubleshooting

### Action fails with "AIMD binary not found"

The action looks for AIMD in this order:

1. `vendor/bin/aimd` — if installed as a project dependency
2. `bin/aimd` — if running in the AIMD repository itself
3. Falls back to global installation via `composer global require`

Ensure your `composer.json` includes AIMD as a dev dependency:

```json
{
  "require-dev": {
    "fractalizer/ai-mess-detector": "^1.0"
  }
}
```

### SARIF upload fails

Ensure correct permissions:

```yaml
permissions:
  security-events: write
  contents: read
```

### Working directory issues

If your PHP project is in a subdirectory:

```yaml
with:
  working-directory: './backend'
  paths: 'src/'
```

## Performance Tips

1. **Use caching** for composer dependencies:

    ```yaml
    - name: Cache composer dependencies
      uses: actions/cache@v4
      with:
        path: ~/.composer/cache
        key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
    ```

2. **Use baseline** to focus on new issues only
3. **Limit paths** to relevant source directories
