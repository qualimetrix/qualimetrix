# Installation

## Requirements

- **PHP 8.4** or higher
- **Composer** (any modern version)

!!! tip
    Not sure which PHP version you have? Run `php -v` in your terminal.

---

## Composer (recommended)

Install Qualimetrix as a development dependency in your project:

```bash
composer require --dev qualimetrix/qualimetrix
```

After installation, the `qmx` binary is available at:

```bash
vendor/bin/qmx
```

---

## PHAR

A standalone archive that runs without adding Qualimetrix to your project's dependencies. Download
`qmx.phar` from the [latest release](https://github.com/qualimetrix/qualimetrix/releases/latest),
make it executable, and run it:

```bash
chmod +x qmx.phar
./qmx.phar check src/
```

!!! warning "Keep the `.phar` suffix"
    Parallel analysis copies the whole archive into your temporary directory on every run when the
    file it runs from is not named `*.phar`. Renaming it to `qmx` costs several megabytes of copying
    per run.

Three differences from a Composer install:

- `hook:install` is not available. The hook is a symlink to a shell script, and nothing can point a
  symlink inside an archive. Write `.git/hooks/pre-commit` by hand, calling the archive on the
  staged files.
- HTML reports label the analysed project `qualimetrix/qualimetrix` unless you say otherwise. The
  label falls back to the root package name, which inside the archive is Qualimetrix's own. Pass
  `--format-opt=project-name=your/project` to set it.
- Releases published before this feature carry no `qmx.phar` asset; the first release that does is
  the one whose changelog entry announces it.

!!! note "Building it yourself"
    From a checkout of this repository, `composer phar` writes `build/qmx.phar`. It fetches its
    build tool from GitHub, so it needs the [GitHub CLI](https://cli.github.com/) on `PATH`.

---

## Docker

Run Qualimetrix in a container without installing PHP locally:

```bash
docker run --rm -v $(pwd):/app qmx check src/
```

This mounts your current directory into the container and analyzes the `src/` folder.

You can pass any CLI options after `check`:

```bash
# JSON output
docker run --rm -v $(pwd):/app qmx check src/ --format=json

# With baseline
docker run --rm -v $(pwd):/app qmx check src/ --baseline=baseline.json
```

---

## Verifying Installation

=== "Composer"

    ```bash
    vendor/bin/qmx --version
    ```

=== "Global or bin-dir"

    ```bash
    bin/qmx --version
    ```

=== "PHAR"

    ```bash
    ./qmx.phar --version
    ```

=== "Docker"

    ```bash
    docker run --rm qmx --version
    ```

You should see output like:

```
Qualimetrix x.x.x
```

---

## What's Next?

Head to the [Quick Start](quick-start.md) guide to run your first analysis and set up integration with your workflow.
