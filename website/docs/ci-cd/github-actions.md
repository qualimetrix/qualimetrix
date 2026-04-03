# GitHub Actions Integration

The Qualimetrix provides a GitHub Action for easy integration into your CI/CD pipelines.

## Quick Start

```yaml
# .github/workflows/quality.yml
name: Code Quality

on: [push, pull_request]

jobs:
  qmx:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run Qualimetrix
        uses: qualimetrix/qualimetrix@v1
        with:
          paths: 'src/'
          baseline: 'baseline.json'
```

## Inputs

| Input               | Description                                      | Required | Default |
| ------------------- | ------------------------------------------------ | -------- | ------- |
| `paths`             | Paths to analyze (space-separated)               | No       | `src/`  |
| `baseline`          | Path to baseline file                            | No       | -       |
| `config`            | Path to config file                              | No       | -       |
| `format`            | Output format: `text`, `json`, `sarif`, `gitlab` | No       | `text`  |
| `php-version`       | PHP version to use                               | No       | `8.4`   |
| `working-directory` | Working directory for analysis                   | No       | `.`     |

## Outputs

| Output       | Description                                                       |
| ------------ | ----------------------------------------------------------------- |
| `violations` | Number of violations found                                        |
| `exit-code`  | Exit code (0 = clean, 1 = warnings, 2 = errors, 3 = config error) |

## Examples

### With Baseline

```yaml
- name: Run Qualimetrix
  uses: qualimetrix/qualimetrix@v1
  with:
    paths: 'src/'
    baseline: 'baseline.json'
```

### Multiple Paths

```yaml
- name: Run Qualimetrix
  uses: qualimetrix/qualimetrix@v1
  with:
    paths: 'src/ lib/ app/'
    config: 'qmx.yaml'
```

### SARIF Output for GitHub Security Tab

```yaml
jobs:
  qmx:
    runs-on: ubuntu-latest
    permissions:
      security-events: write
      contents: read

    steps:
      - uses: actions/checkout@v4

      - name: Run Qualimetrix
        id: qmx
        uses: qualimetrix/qualimetrix@v1
        with:
          paths: 'src/'
          format: 'sarif'
        continue-on-error: true

      - name: Upload SARIF to GitHub Security
        uses: github/codeql-action/upload-sarif@v3
        if: always()
        with:
          sarif_file: results.sarif
          category: qmx

      - name: Fail if violations found
        if: steps.qmx.outputs.exit-code != '0'
        run: exit ${{ steps.qmx.outputs.exit-code }}
```

### Inline PR Annotations (Recommended)

The simplest way to see violations directly in your PR diff. No extra upload steps needed.

```yaml
jobs:
  qmx:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install dependencies
        run: composer install --no-dev

      - name: Run Qualimetrix
        run: vendor/bin/qmx check src/ --format=github --no-progress
```

Violations appear as warning and error annotations directly on the changed lines. By default, only errors cause a non-zero exit code — warnings are shown but don't fail the build.

!!! tip
    For both inline annotations AND Security tab results, run Qualimetrix twice — once with `--format=github` and once with `--format=sarif`.

### JSON Output with Artifacts

```yaml
- name: Run Qualimetrix
  uses: qualimetrix/qualimetrix@v1
  with:
    paths: 'src/'
    format: 'json'

- name: Upload results
  if: always()
  uses: actions/upload-artifact@v4
  with:
    name: qmx-results
    path: qmx-results.json
```

### Using Outputs

```yaml
- name: Run Qualimetrix
  id: qmx
  uses: qualimetrix/qualimetrix@v1
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
        body: `## Qualimetrix Results\n\n` +
              `Violations found: ${{ steps.qmx.outputs.violations }}\n` +
              `Exit code: ${{ steps.qmx.outputs.exit-code }}`
      })
```

### Matrix Testing

```yaml
jobs:
  qmx:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php-version: ['8.3', '8.4']

    steps:
      - uses: actions/checkout@v4

      - name: Run Qualimetrix
        uses: qualimetrix/qualimetrix@v1
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
  qmx-basic:
    name: Qualimetrix
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run Qualimetrix
        uses: qualimetrix/qualimetrix@v1
        with:
          paths: 'src/'
          baseline: 'baseline.json'
          format: 'text'

  qmx-sarif:
    name: Qualimetrix (SARIF)
    runs-on: ubuntu-latest
    permissions:
      security-events: write
      contents: read
    steps:
      - uses: actions/checkout@v4

      - name: Run Qualimetrix
        id: qmx
        uses: qualimetrix/qualimetrix@v1
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
          category: qmx

      - name: Fail if violations found
        if: steps.qmx.outputs.exit-code != '0'
        run: exit ${{ steps.qmx.outputs.exit-code }}
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

      - name: Run Qualimetrix
        uses: qualimetrix/qualimetrix@v1
        with:
          paths: 'src/'
```

## Troubleshooting

### Action fails with "Qualimetrix binary not found"

The action looks for Qualimetrix in this order:

1. `vendor/bin/qmx` — if installed as a project dependency
2. `bin/qmx` — if running in the Qualimetrix repository itself
3. Falls back to global installation via `composer global require`

Ensure your `composer.json` includes Qualimetrix as a dev dependency:

```json
{
  "require-dev": {
    "qualimetrix/qualimetrix": "^1.0"
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
