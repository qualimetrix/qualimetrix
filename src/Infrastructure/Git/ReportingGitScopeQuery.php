<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeResult;

/**
 * Filters findings to show only those in changed files.
 *
 * This filter is used for --report=git:... to show only findings
 * in files that were changed according to the git scope.
 *
 * By default, it also includes findings for parent namespaces of changed files.
 * This can be disabled with --report-strict.
 */
final readonly class ReportingGitScopeQuery implements GitScopeQueryInterface
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Builds index of changed files and namespaces.
     */
    public function resolve(GitScopeRequest $request): GitScopeResult
    {
        $changedFiles = (new GitClient($request->projectRoot, $this->logger))->getChangedFiles($request->reference);
        $paths = [];
        $namespaces = [];

        foreach ($changedFiles as $file) {
            if ($file->isDeleted() || !$file->isPhp()) {
                continue;
            }

            // Path is already project-relative (translated at the git boundary
            // in ChangedFile::fromGitOutput).
            $paths[$file->path->value()] = true;

            // Extract namespace from file. Path is project-relative, so join
            // against the explicit project root (NOT git top-level — the two
            // differ when the project sits in a git subdirectory).
            $fullPath = $request->projectRoot->joinRelative($file->path);
            if (!$request->includeParentNamespaces) {
                continue;
            }

            $this->indexFileNamespaces($fullPath, $namespaces);
        }

        return new GitScopeResult(array_keys($paths), array_keys($namespaces));
    }

    /** @param array<string, true> $namespaces */
    private function indexFileNamespaces(AbsolutePath $filePath, array &$namespaces): void
    {
        if (!self::isRegularFile($filePath)) {
            return;
        }

        foreach ($this->extractNamespaces($filePath) as $namespace) {
            self::indexNamespaceParents($namespace, $namespaces);
        }
    }

    private static function isRegularFile(AbsolutePath $filePath): bool
    {
        // @qmx-ignore-next-line code-smell.error-suppression -- A missing local entry omits optional namespaces while its changed PHP path remains reportable.
        $entry = @lstat($filePath->value());

        return $entry !== false && ($entry['mode'] & 0o170000) === 0o100000;
    }

    /** @param array<string, true> $namespaces */
    private static function indexNamespaceParents(string $namespace, array &$namespaces): void
    {
        $parts = explode('\\', $namespace);
        while ($parts !== []) {
            $ns = implode('\\', $parts);
            $namespaces[$ns] = true;
            array_pop($parts);
        }
    }

    /**
     * Extracts all declared namespaces from a PHP file without full parsing.
     *
     * @return list<string>
     */
    private function extractNamespaces(AbsolutePath $filePath): array
    {
        $content = file_get_contents($filePath->value());

        if ($content === false) {
            return [];
        }

        $namespaces = [];
        $tokens = token_get_all($content);
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_NAMESPACE) {
                continue;
            }

            $namespace = '';
            for (++$i; $i < $count; ++$i) {
                $token = $tokens[$i];
                if ($token === ';' || $token === '{') {
                    break;
                }
                if (\is_array($token) && \in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NS_SEPARATOR], true)) {
                    $namespace .= $token[1];
                }
            }

            if ($namespace !== '') {
                $namespaces[$namespace] = true;
            }
        }

        return array_keys($namespaces);
    }
}
