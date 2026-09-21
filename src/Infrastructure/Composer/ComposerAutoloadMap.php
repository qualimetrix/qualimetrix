<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;

/**
 * Where the analysed project's install says its classes live.
 *
 * Every source is data -- `composer.json`, `installed.json`, and a generated
 * classmap that {@see GeneratedClassmap} parses rather than includes. Nothing
 * here loads a class: loading is what put the tool's own dependencies in the
 * answers, and what let an analysed file's top-level code run inside this
 * process.
 */
final class ComposerAutoloadMap implements AnalysedInstallAnchorInterface
{
    /** @var array<string, list<string>> prefix => directories */
    private array $psr4 = [];

    /** @var array<string, string> FQCN => file */
    private array $classmap = [];

    private bool $loaded = false;

    /** @var list<string> */
    private array $roots = [];

    public function __construct(
        private readonly InstallLocator $locator = new InstallLocator(),
        private readonly GeneratedClassmap $generatedClassmap = new GeneratedClassmap(),
    ) {}

    /**
     * @param list<string> $analysedPaths
     */
    public function pointAt(string $projectRoot, array $analysedPaths): void
    {
        $this->roots = $this->locator->rootsFor($projectRoot, $analysedPaths);
        $this->psr4 = [];
        $this->classmap = [];
        $this->loaded = false;
    }

    public function isConfigured(): bool
    {
        return $this->roots !== [];
    }

    public function fileFor(string $fqcn): ?string
    {
        $this->load();

        $normalized = ltrim($fqcn, '\\');

        if (isset($this->classmap[$normalized])) {
            return $this->within($this->classmap[$normalized]);
        }

        foreach ($this->psr4 as $prefix => $directories) {
            if ($prefix !== '' && !str_starts_with($normalized, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($normalized, \strlen($prefix))) . '.php';

            foreach ($directories as $directory) {
                $candidate = $this->within($directory . '/' . $relative);

                if ($candidate !== null && is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * The same path, or null when it lies outside every project this run may
     * read.
     *
     * The values behind these paths come from files the analysed tree controls:
     * an `install-path` or a psr-4 target may contain `..`, and a classmap
     * entry is whatever was generated into it. Without this the map would hand
     * back a path anywhere on the machine and the reader would open it. The old
     * mechanism had the same exposure and worse -- it executed what it found --
     * but that is a reason to state the boundary, not to inherit the silence.
     */
    private function within(string $path): ?string
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            return null;
        }

        foreach ($this->roots as $root) {
            if ($resolved === $root || str_starts_with($resolved, $root . '/')) {
                return $resolved;
            }
        }

        return null;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        foreach ($this->roots as $root) {
            $this->readProject($root);
        }

        // Longest prefix first, so a more specific declaration wins over a
        // shorter one that also matches.
        krsort($this->psr4);
    }

    private function readProject(string $root): void
    {
        $manifest = $this->decode($root . '/composer.json');
        $vendor = $root . '/' . ($manifest['config']['vendor-dir'] ?? 'vendor');

        $this->addPsr4($manifest['autoload']['psr-4'] ?? null, $root);
        $this->addPsr4($manifest['autoload-dev']['psr-4'] ?? null, $root);
        $this->readInstalledPackages($vendor);

        foreach ($this->generatedClassmap->read($root, $vendor) as $fqcn => $file) {
            $this->classmap[$fqcn] ??= $file;
        }
    }

    private function readInstalledPackages(string $vendor): void
    {
        $packages = $this->decode($vendor . '/composer/installed.json')['packages'] ?? null;

        if (!\is_array($packages)) {
            return;
        }

        foreach ($packages as $package) {
            if (!\is_array($package)) {
                continue;
            }

            // `install-path` is relative to `vendor/composer/`, not to vendor.
            $this->addPsr4(
                $package['autoload']['psr-4'] ?? null,
                $vendor . '/composer/' . ($package['install-path'] ?? ''),
            );
        }
    }

    private function addPsr4(mixed $section, string $base): void
    {
        if (!\is_array($section)) {
            return;
        }

        foreach ($section as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $directory = \is_string($prefix) && \is_string($path)
                    ? realpath(rtrim($base . '/' . $path, '/'))
                    : false;

                if ($directory !== false) {
                    $this->psr4[$prefix][] = $directory;
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $file): array
    {
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
