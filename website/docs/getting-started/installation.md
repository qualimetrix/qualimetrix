# Installation

## Requirements

- **PHP 8.4** or higher
- **Composer** (any modern version)

!!! tip
    Not sure which PHP version you have? Run `php -v` in your terminal.

---

## Composer (recommended)

Install AI Mess Detector as a development dependency in your project:

```bash
composer require --dev fractalizer/ai-mess-detector
```

After installation, the `aimd` binary is available at:

```bash
vendor/bin/aimd
```

---

## PHAR

!!! note "Coming soon"
    A standalone PHAR archive is planned for future releases. This will allow you to run AI Mess Detector without adding it to your project's dependencies.

---

## Docker

Run AI Mess Detector in a container without installing PHP locally:

```bash
docker run --rm -v $(pwd):/app aimd check src/
```

This mounts your current directory into the container and analyzes the `src/` folder.

You can pass any CLI options after `analyze`:

```bash
# JSON output
docker run --rm -v $(pwd):/app aimd check src/ --format=json

# With baseline
docker run --rm -v $(pwd):/app aimd check src/ --baseline=baseline.json
```

---

## Verifying Installation

=== "Composer"

    ```bash
    vendor/bin/aimd --version
    ```

=== "Global or bin-dir"

    ```bash
    bin/aimd --version
    ```

=== "Docker"

    ```bash
    docker run --rm aimd --version
    ```

You should see output like:

```
AI Mess Detector x.x.x
```

---

## What's Next?

Head to the [Quick Start](quick-start.md) guide to run your first analysis and set up integration with your workflow.
