# Quick Start

Three ways to integrate Qualimetrix into your project:

1. **Pre-commit Hook** — check before every commit
2. **GitHub Action** — automatic checks in CI/CD
3. **Docker** — run without installing PHP locally

---

## Your First Analysis

### Install

```bash
composer require --dev qualimetrix/qualimetrix
```

### Run the analysis

```bash
vendor/bin/qmx check src/
```

<!-- llms:skip-begin -->
### Interpret the output

The default output shows a health summary with scores by category:

```
Qualimetrix — 127 files analyzed, 2.3s

Health ████████████████████░░░░░░░░░░ 67.2% Fair

  Complexity      ██████████████████████████░░░░ 85.1% Excellent
  Cohesion        ████████████░░░░░░░░░░░░░░░░░░ 42.3% Poor
  Coupling        █████████████████░░░░░░░░░░░░░ 55.8% Fair
  Typing          ████████████████████████████░░ 92.0% Excellent
  Maintainability ████████████████████░░░░░░░░░░ 64.5% Good

Worst namespaces
  38 App\Service (12 classes, 28 violations) — low cohesion, high coupling
  42 App\Repository (8 classes, 15 violations) — low cohesion

45 violations (12 errors, 33 warnings) | Tech debt: 2d 4h (8.5 min/kLOC)

Hints: --detail to see violations (top 200) | --namespace='App\Service' to drill down | --format=health -o report.html for full report
```

Each category gets a label: **Excellent** (top quality), **Good** (solid), **Fair** (room for improvement), **Poor** (needs attention), or **Critical** (action required). The "Worst namespaces" section highlights where to focus first.

<!-- llms:skip-end -->

### Drill down into a namespace

Investigate a specific namespace to see its classes and violations:

```bash
vendor/bin/qmx check src/ --namespace='App\Service'
```

### See detailed violations

List individual violations with file paths, line numbers, and remediation hints:

```bash
vendor/bin/qmx check src/ --detail
```

### Generate an HTML report

For a full interactive report with charts and drill-down navigation:

```bash
vendor/bin/qmx check src/ --format=health -o report.html
```

Open `report.html` in your browser to explore the results.

---

## 1. Pre-commit Hook

Automatic checking of staged files before every commit.

### Installation

=== "Symbolic Link (recommended)"

    ```bash
    ln -s ../../scripts/pre-commit-hook.sh .git/hooks/pre-commit
    ```

    Automatic updates when the script changes, no need to copy on updates.

=== "Copy"

    ```bash
    cp scripts/pre-commit-hook.sh .git/hooks/pre-commit
    chmod +x .git/hooks/pre-commit
    ```

    Works if `.git/hooks` does not support symlinks, can be modified per project.

=== "Built-in command"

    ```bash
    vendor/bin/qmx hook:install
    ```

### Usage

After installation, the hook runs automatically on every `git commit`:

```bash
git add src/MyClass.php
git commit -m "Add new feature"

# Hook will run automatically:
# Running Qualimetrix on staged files...
# Qualimetrix passed.
```

### Bypassing the Hook

```bash
# Skip the check for a specific commit
git commit --no-verify -m "WIP: work in progress"
```

### Setting Up Baseline

If the project already contains legacy code with violations:

```bash
# Create a baseline for existing issues
vendor/bin/qmx check src/ --generate-baseline=baseline.json

# Now the hook will ignore issues from the baseline
git commit -m "Add feature"
```

### Removing the Hook

```bash
rm .git/hooks/pre-commit
```

---

## 2. GitHub Action

Automatic analysis on push and pull request. See the [GitHub Actions guide](../ci-cd/github-actions.md) for detailed configuration.

### Quick Setup

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

---

<!-- llms:skip-begin -->
## 3. Docker

Run analysis in a container without installing PHP locally.

### Building the Image

```bash
docker build -t qmx .
```

### Usage

```bash
<!-- llms:skip-end -->

# Analyze the current directory
docker run --rm -v $(pwd):/app qmx check src/

# With baseline
docker run --rm -v $(pwd):/app qmx check src/ --baseline=baseline.json

# With configuration
docker run --rm -v $(pwd):/app qmx check src/ --config=qmx.yaml

# JSON output
docker run --rm -v $(pwd):/app qmx check src/ --format=json
```

### Docker Compose

```yaml
# docker-compose.yml
services:
  qmx:
    image: qmx:latest
    volumes:
      - .:/app:ro
      - ./baseline.json:/app/baseline.json
    command: check src/ --baseline=baseline.json
```

```bash
docker-compose run --rm qmx
```

### CI/CD with Docker

=== "GitLab CI"

    ```yaml
    # .gitlab-ci.yml
    qmx:
      stage: test
      image: qmx:latest
      script:
        - qmx check src/ --baseline=baseline.json
      artifacts:
        when: on_failure
        paths:
          - qmx-results.json
    ```

=== "Jenkins"

    ```groovy
    // Jenkinsfile
    pipeline {
        agent any
        stages {
            stage('Qualimetrix Analysis') {
                steps {
                    script {
                        docker.image('qmx:latest').inside('-v $WORKSPACE:/app') {
                            sh 'qmx check src/ --baseline=baseline.json'
                        }
                    }
                }
            }
        }
    }
    ```

---

## Excluding Paths

Suppress violations for files matching glob patterns. Useful for generated code, DTOs, or entity classes.

!!! note
    Excluded files are still analyzed (metrics are collected) — only violations are suppressed.

=== "YAML Configuration"

    ```yaml
    # qmx.yaml
    exclude_paths:
      - src/Entity/*
      - src/DTO/*
    ```

=== "CLI"

    ```bash
    vendor/bin/qmx check src/ --exclude-path='src/Entity/*' --exclude-path='*/DTO/*'
    ```

CLI patterns are merged with those defined in the config file.

---

<!-- llms:skip-begin -->
## Method Comparison

| Method              | When to use       | Advantages                                | Disadvantages                      |
| ------------------- | ----------------- | ----------------------------------------- | ---------------------------------- |
| **Pre-commit Hook** | Local development | Fast feedback, prevents bad commits       | Can be bypassed with `--no-verify` |
| **GitHub Action**   | CI/CD pipeline    | Automatic for all PRs, cannot be bypassed | Slower than local                  |
| **Docker**          | Clean environment | No local PHP needed, reproducible         | Requires Docker, slower            |

### Recommended Strategy

- **Small teams (1-5):** Pre-commit Hook + GitHub Action
- **Medium teams (5-20):** GitHub Action (required) + Pre-commit Hook (optional) + Docker for devs without PHP
- **Large teams (20+):** GitHub Action with baseline (required) + Docker

---

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
## Troubleshooting

### Pre-commit Hook Not Working

**Hook does not run on commit:**

```bash
<!-- llms:skip-end -->

# Check that the hook exists and is executable
ls -la .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

**`qmx binary not found`:**

```bash
composer install
ls -la bin/qmx
```

### Docker Permission Issues

```bash
# Linux with SELinux: add :z flag
docker run --rm -v $(pwd):/app:z qmx check src/
```
