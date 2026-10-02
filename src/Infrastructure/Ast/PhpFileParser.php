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
     * @return Node[]
     */
    public function parseContent(SplFileInfo $file, string $content): array
    {
        $filePath = $file->getPathname();
        $absolutePath = $this->resolveAbsolutePath($file);

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
     * The caller provides the absolute identity of the source snapshot.
     */
    private function resolveAbsolutePath(SplFileInfo $file): AbsolutePath
    {
        return AbsolutePath::fromString($file->getPathname());
    }
}
