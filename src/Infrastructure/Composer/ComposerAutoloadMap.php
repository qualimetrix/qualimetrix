<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;
use Qualimetrix\Infrastructure\Composer\Contract\ComposerRootOmission;

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

    /** @var list<ComposerRootOmission> */
    private array $omissions = [];

    private readonly ComposerClassPathLookup $classPathLookup;

    private readonly GeneratedClassmap $generatedClassmap;

    public function __construct(
        private readonly ComposerManifestReaderInterface $manifestReader,
        private readonly InstallLocator $locator = new InstallLocator(),
        ?GeneratedClassmap $generatedClassmap = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        // Built here rather than defaulted in the signature so the classmap
        // reader reports through the same channel as this one: a default in
        // the signature cannot see another parameter.
        $this->generatedClassmap = $generatedClassmap ?? new GeneratedClassmap(logger: $this->logger);
        $this->classPathLookup = new ComposerClassPathLookup();
    }

    /**
     * @param list<string> $analysedPaths
     */
    public function pointAt(string $projectRoot, array $analysedPaths): void
    {
        $located = $this->locator->rootsFor($projectRoot, $analysedPaths);
        $this->omissions = $located->omissions;
        $this->psr4 = [];
        $this->classmap = [];
        $this->classPathLookup->pointAt($located->roots);
        $this->loaded = false;
    }

    public function observedRootOmissions(): array
    {
        return $this->omissions;
    }

    public function isConfigured(): bool
    {
        return $this->classPathLookup->isConfigured();
    }

    public function fileFor(string $fqcn): ?string
    {
        $this->load();

        $normalized = ltrim($fqcn, '\\');

        if (isset($this->classmap[$normalized])) {
            return $this->classPathLookup->within($this->classmap[$normalized]);
        }

        foreach ($this->psr4 as $prefix => $directories) {
            if ($prefix !== '' && !str_starts_with($normalized, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($normalized, \strlen($prefix))) . '.php';

            foreach ($directories as $directory) {
                $candidate = $this->classPathLookup->within($directory . '/' . $relative);

                if ($candidate !== null && is_file($candidate)) {
                    return $candidate;
                }
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

        foreach ($this->classPathLookup->roots() as $root) {
            $this->readProject($root);
        }

        // Longest prefix first, so a more specific declaration wins over a
        // shorter one that also matches.
        krsort($this->psr4);
    }

    private function readProject(string $root): void
    {
        $manifest = $this->manifestReader->read(AbsolutePath::fromString($root));
        foreach ($manifest->issues as $issue) {
            if ($issue->kind !== \Qualimetrix\Analysis\ProjectManifest\Contract\ManifestIssueKind::Absent) {
                $this->logger->warning(\sprintf('Cannot use "%s": %s. Classes rejected by the manifest are treated as external.', $issue->source, $issue->kind->value === 'invalid-json' ? 'invalid JSON — ' . $issue->detail : $issue->detail));
            }
        }
        $vendorPath = str_starts_with($manifest->vendorDirectory, '/')
            ? $manifest->vendorDirectory
            : $root . '/' . $manifest->vendorDirectory;
        $vendor = $this->classPathLookup->existingPath($vendorPath);

        $this->addPsr4($manifest->psr4Roots(), $root);
        if ($vendor === null) {
            return;
        }

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
                    ? $this->classPathLookup->existingPath(rtrim($base . '/' . $path, '/'))
                    : null;

                if ($directory !== null) {
                    $this->psr4[$prefix][] = $directory;
                }
            }
        }
    }

    /**
     * One manifest, and what it cost to not get one.
     *
     * The three ways of ending up with nothing are not the same answer, and
     * collapsing them is what made a damaged manifest indistinguishable from a
     * project that simply has none. Absent is legitimate and stays silent: the
     * run already says it found no install to follow classes through. Present
     * but unopenable, and present but not JSON, are states of the analysed
     * tree that change published metrics — DIT stops one link early and the
     * class becomes external coupling — while every message downstream names a
     * different cause. Each is reported here, where the cause is still known.
     *
     * @return array<string, mixed>
     */
    private function decode(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            $this->logger->warning(\sprintf(
                'Cannot read "%s": the file exists but could not be opened. '
                . 'Classes it would place are treated as if the file declared nothing.',
                $file,
            ));

            return [];
        }

        $decoded = json_decode($raw, true);

        if (\is_array($decoded)) {
            return $decoded;
        }

        $this->logger->warning(\sprintf(
            'Cannot use "%s": %s. Classes it would place are treated as if the file declared nothing.',
            $file,
            json_last_error() === \JSON_ERROR_NONE
                ? 'the top-level value is not a JSON object'
                : 'invalid JSON — ' . json_last_error_msg(),
        ));

        return [];
    }
}
