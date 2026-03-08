# Quick Start

Three ways to integrate AI Mess Detector into your project:

1. **Pre-commit Hook** — check before every commit
2. **GitHub Action** — automatic checks in CI/CD
3. **Docker** — run without installing PHP locally

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
    bin/aimd hook:install
    ```

### Usage

After installation, the hook runs automatically on every `git commit`:

```bash
git add src/MyClass.php
git commit -m "Add new feature"

# Hook will run automatically:
# Running AI Mess Detector on staged files...
# AI Mess Detector passed.
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
bin/aimd analyze src/ --generate-baseline=baseline.json

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

---

## 3. Docker

Run analysis in a container without installing PHP locally.

### Building the Image

```bash
docker build -t aimd .
```

### Usage

```bash
# Analyze the current directory
docker run --rm -v $(pwd):/app aimd analyze src/

# With baseline
docker run --rm -v $(pwd):/app aimd analyze src/ --baseline=baseline.json

# With configuration
docker run --rm -v $(pwd):/app aimd analyze src/ --config=aimd.yaml

# JSON output
docker run --rm -v $(pwd):/app aimd analyze src/ --format=json
```

### Docker Compose

```yaml
# docker-compose.yml
services:
  aimd:
    image: aimd:latest
    volumes:
      - .:/app:ro
      - ./baseline.json:/app/baseline.json
    command: analyze src/ --baseline=baseline.json
```

```bash
docker-compose run --rm aimd
```

### CI/CD with Docker

=== "GitLab CI"

    ```yaml
    # .gitlab-ci.yml
    aimd:
      stage: test
      image: aimd:latest
      script:
        - aimd analyze src/ --baseline=baseline.json
      artifacts:
        when: on_failure
        paths:
          - aimd-results.json
    ```

=== "Jenkins"

    ```groovy
    // Jenkinsfile
    pipeline {
        agent any
        stages {
            stage('AIMD Analysis') {
                steps {
                    script {
                        docker.image('aimd:latest').inside('-v $WORKSPACE:/app') {
                            sh 'aimd analyze src/ --baseline=baseline.json'
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
    # aimd.yaml
    exclude_paths:
      - src/Entity/*
      - src/DTO/*
    ```

=== "CLI"

    ```bash
    bin/aimd analyze src/ --exclude-path='src/Entity/*' --exclude-path='*/DTO/*'
    ```

CLI patterns are merged with those defined in the config file.

---

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

## Troubleshooting

### Pre-commit Hook Not Working

**Hook does not run on commit:**

```bash
# Check that the hook exists and is executable
ls -la .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

**`aimd binary not found`:**

```bash
composer install
ls -la bin/aimd
```

### Docker Permission Issues

```bash
# Linux with SELinux: add :z flag
docker run --rm -v $(pwd):/app:z aimd analyze src/
```
