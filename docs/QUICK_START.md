# Quick Start — AI Mess Detector Integration

Three ways to quickly integrate AI Mess Detector into your project:

1. **Pre-commit Hook** — check before every commit
2. **GitHub Action** — automatic checks in CI/CD
3. **Docker** — run without installing PHP locally

---

## 1. Pre-commit Hook

Automatic checking of staged files before every commit.

### Installation

#### Option A: Symbolic Link (recommended)

```bash
# Create a symlink to the script
ln -s ../../scripts/pre-commit-hook.sh .git/hooks/pre-commit
```

**Advantages:**
- Automatic updates when the script changes
- No need to copy on updates

#### Option B: Copy

```bash
# Copy the script to .git/hooks
cp scripts/pre-commit-hook.sh .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

**Advantages:**
- Works if .git/hooks does not support symlinks
- Can be modified for a specific project

### Usage

After installation, the hook will automatically run on every `git commit`:

```bash
git add src/MyClass.php
git commit -m "Add new feature"

# Hook will run automatically:
# Running AI Mess Detector on staged files...
# AI Mess Detector passed.
```

### Bypassing the Hook (when needed)

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
# Remove the hook
rm .git/hooks/pre-commit
```

---

## 2. GitHub Action

Automatic analysis on push and pull request.

### Installation

The workflow is already created in `.github/workflows/aimd.yml` and will automatically run on:
- Push to `main`, `master`, `develop` branches
- Pull request creation targeting these branches

### Configuration

Edit `.github/workflows/aimd.yml` to suit your needs:

#### Change the checked branches

```yaml
on:
  push:
    branches: [main, feature/*]  # Add your branches
  pull_request:
    branches: [main]
```

#### Change the PHP version

```yaml
strategy:
  matrix:
    php-version: ['8.4', '8.3']  # Test on multiple versions
```

#### Add analysis of additional directories

```yaml
- name: Run AI Mess Detector
  run: bin/aimd analyze src/ tests/
```

#### Configure output formats

```yaml
- name: Run AI Mess Detector
  run: |
    bin/aimd analyze src/ --format=json > aimd-results.json
    bin/aimd analyze src/ --format=text
```

### Viewing Results

1. Go to the **Actions** tab in your GitHub repository
2. Find the **AI Mess Detector** workflow
3. Open a specific run to view logs

On errors, results will be uploaded as artifacts:

1. Open the failed workflow run
2. Download the `aimd-results` artifact
3. Review `aimd-report.txt` or `aimd-results.json`

### Baseline Integration

Add `baseline.json` to your repository:

```bash
# Create baseline
bin/aimd analyze src/ --generate-baseline=baseline.json

# Commit it
git add baseline.json
git commit -m "chore: add aimd baseline"
git push
```

The workflow will automatically use the baseline if the file exists.

### Disabling the Workflow

To temporarily disable:

```yaml
# Add to the top of .github/workflows/aimd.yml
on:
  workflow_dispatch:  # Manual trigger only
```

Or completely remove the file:

```bash
rm .github/workflows/aimd.yml
```

---

## 3. Docker

Run analysis in a container without installing PHP locally.

### Building the Image

```bash
# Build locally
docker build -t aimd .

# Or use multi-platform build
docker buildx build --platform linux/amd64,linux/arm64 -t aimd .
```

### Usage

#### Basic Analysis

```bash
# Analyze the current directory
docker run --rm -v $(pwd):/app aimd analyze src/

# Windows (PowerShell)
docker run --rm -v ${PWD}:/app aimd analyze src/
```

#### With Baseline

```bash
# Use an existing baseline
docker run --rm -v $(pwd):/app aimd analyze src/ --baseline=baseline.json

# Create a new baseline
docker run --rm -v $(pwd):/app aimd analyze src/ --generate-baseline=baseline.json
```

#### With Configuration

```bash
# Use a custom configuration
docker run --rm -v $(pwd):/app aimd analyze src/ --config=aimd.yaml
```

#### Different Output Formats

```bash
# JSON format
docker run --rm -v $(pwd):/app aimd analyze src/ --format=json

# Checkstyle XML (for IDEs)
docker run --rm -v $(pwd):/app aimd analyze src/ --format=checkstyle > checkstyle.xml
```

#### Interactive Mode

```bash
# Enter the container for debugging
docker run --rm -it -v $(pwd):/app aimd sh

# Inside the container
/app $ aimd analyze src/
/app $ aimd analyze --help
/app $ exit
```

### Docker Compose

Create a `docker-compose.yml` in the project root:

```yaml
version: '3.8'

services:
  aimd:
    image: aimd:latest
    volumes:
      - .:/app:ro
      - ./baseline.json:/app/baseline.json
    command: analyze src/ --baseline=baseline.json
```

Run:

```bash
# Build the image
docker-compose build

# Run analysis
docker-compose run --rm aimd

# With custom arguments
docker-compose run --rm aimd analyze tests/
```

### CI/CD with Docker

#### GitLab CI

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

#### Jenkins

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

### Publishing the Image

After testing, you can publish to Docker Hub:

```bash
# Login
docker login

# Tag
docker tag aimd:latest yourusername/aimd:latest
docker tag aimd:latest yourusername/aimd:1.0

# Push
docker push yourusername/aimd:latest
docker push yourusername/aimd:1.0
```

Then others can use:

```bash
docker pull yourusername/aimd:latest
docker run --rm -v $(pwd):/app yourusername/aimd analyze src/
```

---

## Method Comparison

| Method | When to use | Advantages | Disadvantages |
|--------|-------------|------------|---------------|
| **Pre-commit Hook** | Local development | Fast feedback<br>Prevents bad commits<br>No CI/CD required | Can be bypassed with --no-verify<br>Needs setup on each machine |
| **GitHub Action** | CI/CD pipeline | Automatic check for all PRs<br>Cannot be bypassed<br>Result history | Slower than local<br>Requires GitHub |
| **Docker** | Clean environment | No local PHP installation needed<br>Reproducibility<br>Works everywhere | Requires Docker<br>Slower due to container |

### Recommended Strategy

**Small projects (1-5 developers):**
- Pre-commit Hook for local development
- GitHub Action for PR checks

**Medium projects (5-20 developers):**
- Pre-commit Hook (optional)
- GitHub Action (required)
- Docker for developers without local PHP

**Large projects (20+ developers):**
- GitHub Action (required) with baseline
- Docker for CI/CD and developers
- Pre-commit Hook (at the team's discretion)

---

## Troubleshooting

### Pre-commit Hook Not Working

**Problem:** Hook does not run on commit

**Solution:**
```bash
# Check that the hook exists and is executable
ls -la .git/hooks/pre-commit

# If not executable
chmod +x .git/hooks/pre-commit

# Check that it is the correct file
cat .git/hooks/pre-commit | head -n 5
```

**Problem:** `aimd binary not found`

**Solution:**
```bash
# Install dependencies
composer install

# Check that bin/aimd exists
ls -la bin/aimd
```

### GitHub Action Fails

**Problem:** Workflow fails on `composer install`

**Solution:**
```yaml
# Add to workflow:
- name: Validate composer
  run: composer validate

- name: Install dependencies
  run: composer install --no-dev --no-scripts
```

**Problem:** Slow dependency installation

**Solution:** Use caching (already configured in `.github/workflows/aimd.yml`)

### Docker Issues

**Problem:** `permission denied` when mounting a volume

**Solution:**
```bash
# Linux: add :z flag for SELinux
docker run --rm -v $(pwd):/app:z aimd analyze src/

# Or change permissions
chmod -R 755 src/
```

**Problem:** Slow image build

**Solution:**
```bash
# Use BuildKit
DOCKER_BUILDKIT=1 docker build -t aimd .

# Or add .dockerignore
echo "vendor/" >> .dockerignore
echo "tests/" >> .dockerignore
echo ".git/" >> .dockerignore
```

---

## Additional Resources

- **Documentation:** [docs/](.)

---

## Feedback

Found a bug or have a suggestion? Create an issue in the GitHub repository!
