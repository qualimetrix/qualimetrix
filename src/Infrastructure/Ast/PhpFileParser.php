<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Ast;

use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Path\AbsolutePath;
use SplFileInfo;
use Throwable;

/**
 * PHP file parser implementation using nikic/php-parser.
 */
final class PhpFileParser implements FileParserInterface
{
    private readonly Parser $parser;

    public function __construct(
        ?Parser $parser = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @throws ParseException
     *
     * @return Node[]
     */
    public function parse(SplFileInfo $file): array
    {
        $filePath = $file->getPathname();
        $absolutePath = $this->resolveAbsolutePath($file);

        if (!$file->isFile()) {
            $this->logger->warning('File does not exist or is not a regular file', [
                'file' => $filePath,
            ]);
            throw new ParseException($absolutePath, 'File does not exist or is not a regular file');
        }

        if (!$file->isReadable()) {
            $this->logger->warning('File is not readable', [
                'file' => $filePath,
            ]);
            throw new ParseException($absolutePath, 'File is not readable');
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            $this->logger->warning('Failed to read file contents', [
                'file' => $filePath,
            ]);
            throw new ParseException($absolutePath, 'Failed to read file contents');
        }

        $this->logger->debug('Parsing file', [
            'file' => $filePath,
            'size' => \strlen($content),
        ]);

        try {
            $ast = $this->parser->parse($content);
        } catch (Throwable $e) {
            $this->logger->warning('Parse error', [
                'file' => $filePath,
                'message' => $e->getMessage(),
            ]);
            throw new ParseException($absolutePath, $e->getMessage(), $e);
        }

        if ($ast === null) {
            $this->logger->warning('Parser returned null (syntax error)', [
                'file' => $filePath,
            ]);
            throw new ParseException($absolutePath, 'Parser returned null (syntax error)');
        }

        $this->logger->debug('Parsed successfully', [
            'file' => $filePath,
            'nodes' => \count($ast),
        ]);

        return $ast;
    }

    /**
     * Resolves the parser's view of the file to an absolute path.
     *
     * SplFileInfo may carry a relative path when callers pass relative
     * arguments (e.g. `bin/qmx check src/`). The {@see ParseException}
     * identity is the absolute path of the file we tried to read, so the
     * resolution happens eagerly:
     * 1. `getRealPath()` for real files (handles `.`, `..`, symlinks).
     * 2. Bare {@see SplFileInfo::getPathname()} as fallback (broken symlink,
     *    non-existent path, race).
     * 3. Prepend {@see getcwd()} if the result is still relative — keeps the
     *    AbsolutePath invariant ("starts with /") without touching the disk.
     */
    private function resolveAbsolutePath(SplFileInfo $file): AbsolutePath
    {
        $realPath = $file->isFile() ? $file->getRealPath() : false;
        $resolvedPath = $realPath !== false ? $realPath : $file->getPathname();

        if (str_starts_with($resolvedPath, '/')) {
            return AbsolutePath::fromString($resolvedPath);
        }

        return AbsolutePath::fromString(((string) getcwd()) . '/' . $resolvedPath);
    }
}
