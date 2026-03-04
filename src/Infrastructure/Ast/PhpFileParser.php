<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Ast;

use AiMessDetector\Core\Ast\FileParserInterface;
use AiMessDetector\Core\Exception\ParseException;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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

        if (!$file->isFile()) {
            $this->logger->warning('File does not exist or is not a regular file', [
                'file' => $filePath,
            ]);
            throw new ParseException($filePath, 'File does not exist or is not a regular file');
        }

        if (!$file->isReadable()) {
            $this->logger->warning('File is not readable', [
                'file' => $filePath,
            ]);
            throw new ParseException($filePath, 'File is not readable');
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            $this->logger->warning('Failed to read file contents', [
                'file' => $filePath,
            ]);
            throw new ParseException($filePath, 'Failed to read file contents');
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
            throw new ParseException($filePath, $e->getMessage(), $e);
        }

        if ($ast === null) {
            $this->logger->warning('Parser returned null (syntax error)', [
                'file' => $filePath,
            ]);
            throw new ParseException($filePath, 'Parser returned null (syntax error)');
        }

        $this->logger->debug('Parsed successfully', [
            'file' => $filePath,
            'nodes' => \count($ast),
        ]);

        return $ast;
    }
}
