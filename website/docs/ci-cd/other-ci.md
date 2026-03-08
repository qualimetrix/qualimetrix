# Other CI Systems

AI Mess Detector works with any CI system that can run PHP. This page shows how to set it up in the most popular ones.

## GitLab CI

GitLab has a built-in Code Quality widget that shows issues right in merge requests. AIMD supports the GitLab format natively.

```yaml
# .gitlab-ci.yml
code_quality:
  stage: test
  image: php:8.4-cli
  before_script:
    - composer install --no-dev
  script:
    - vendor/bin/aimd analyze src/ --format=gitlab > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

The `--format=gitlab` flag produces output in GitLab Code Quality format. Once configured, you will see code quality findings directly in your merge request diffs.

### Using a Baseline

To ignore known issues in a legacy project:

```yaml
code_quality:
  stage: test
  image: php:8.4-cli
  before_script:
    - composer install --no-dev
  script:
    - vendor/bin/aimd analyze src/ --format=gitlab --baseline=baseline.json > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

## Jenkins

Jenkins works well with Checkstyle format. The [Warnings Next Generation](https://plugins.jenkins.io/warnings-ng/) plugin can parse Checkstyle XML and display results in the Jenkins UI.

```groovy
pipeline {
    agent any
    stages {
        stage('Code Quality') {
            steps {
                sh 'vendor/bin/aimd analyze src/ --format=checkstyle > checkstyle-result.xml'
                recordIssues tools: [checkStyle(pattern: 'checkstyle-result.xml')]
            }
        }
    }
}
```

Make sure to install the Warnings Next Generation plugin first. It provides the `recordIssues` step used above.

## CircleCI

```yaml
# .circleci/config.yml
version: 2.1

jobs:
  code-quality:
    docker:
      - image: cimg/php:8.4
    steps:
      - checkout
      - run: composer install --no-dev
      - run: vendor/bin/aimd analyze src/
```

### Storing Results as Artifacts

```yaml
jobs:
  code-quality:
    docker:
      - image: cimg/php:8.4
    steps:
      - checkout
      - run: composer install --no-dev
      - run: vendor/bin/aimd analyze src/ --format=json > aimd-results.json
      - store_artifacts:
          path: aimd-results.json
          destination: code-quality
```

## Bitbucket Pipelines

```yaml
# bitbucket-pipelines.yml
pipelines:
  default:
    - step:
        name: Code Quality
        image: php:8.4-cli
        script:
          - composer install --no-dev
          - vendor/bin/aimd analyze src/
```

### With Caching

```yaml
pipelines:
  default:
    - step:
        name: Code Quality
        image: php:8.4-cli
        caches:
          - composer
        script:
          - composer install --no-dev
          - vendor/bin/aimd analyze src/
```

## Generic CI (Any System)

No matter what CI system you use, the setup follows the same steps:

### 1. Install Dependencies

```bash
composer install --no-dev
```

### 2. Run the Analysis

```bash
vendor/bin/aimd analyze src/
```

### 3. Choose the Right Format

Pick the output format that works best with your CI system:

| Format     | Flag                  | Best For                                |
| ---------- | --------------------- | --------------------------------------- |
| Text       | `--format=text`       | Console output, simple CI               |
| JSON       | `--format=json`       | Custom integrations, scripts            |
| Checkstyle | `--format=checkstyle` | Jenkins, tools that read Checkstyle XML |
| SARIF      | `--format=sarif`      | GitHub, VS Code, security dashboards    |
| GitLab     | `--format=gitlab`     | GitLab Code Quality widget              |

### 4. Handle Exit Codes

AIMD uses standard exit codes:

| Exit Code | Meaning             |
| --------- | ------------------- |
| `0`       | No violations found |
| `1`       | Violations found    |

Most CI systems treat a non-zero exit code as a failure. If you want the pipeline to continue even when violations are found, suppress the exit code:

```bash
vendor/bin/aimd analyze src/ || true
```

### 5. Use a Baseline for Legacy Projects

If you are adding AIMD to a project that already has many issues, generate a baseline first:

```bash
vendor/bin/aimd analyze src/ --generate-baseline=baseline.json
```

Then use it in CI to only report new violations:

```bash
vendor/bin/aimd analyze src/ --baseline=baseline.json
```

### 6. Use a Config File

For consistent settings across local and CI environments, use a YAML config file:

```bash
vendor/bin/aimd analyze src/ --config=aimd.yaml
```

## Tips

- **Cache composer dependencies** in your CI system to speed up builds.
- **Use `--no-dev`** when installing composer packages in CI -- AIMD does not need dev dependencies to run.
- **Run AIMD in parallel with other checks** (PHPStan, PHP-CS-Fixer) to save time.
- **Use a baseline** when introducing AIMD into an existing project so CI does not fail on pre-existing issues.
