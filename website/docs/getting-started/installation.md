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

!!! note "Coming soon"
    A standalone PHAR archive is planned for future releases. This will allow you to run Qualimetrix without adding it to your project's dependencies.

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
