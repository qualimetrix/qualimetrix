<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Composer's generated classmap, read without running it.
 *
 * The file is PHP, so including it would execute a file from the tree under
 * measurement -- the thing this whole mechanism exists to stop. It is a literal
 * `return array(...)`, so parsing answers the same question.
 *
 * It is not optional cover for psr-4: of 130 packages in this repository's
 * benchmark install, 25 declare a classmap and no psr-4 at all, `phpunit/phpunit`
 * among them.
 */
final readonly class GeneratedClassmap
{
    /**
     * Parsing builds a syntax tree, which costs a large multiple of the file's
     * own size -- measured at roughly forty times. A generated classmap has no
     * upper bound the analysed project is obliged to respect, so a run reads
     * one up to this size and treats anything larger as no classmap at all: a
     * shallower depth is a worse answer than an exhausted process, but it is
     * still an answer.
     */
    private const int MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private ClassmapPath $path = new ClassmapPath(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return array<string, string> FQCN => file
     */
    public function read(string $projectRoot, string $vendorDirectory): array
    {
        $vendorDirectory = rtrim($vendorDirectory, '/');

        $statements = $this->statements($vendorDirectory . '/composer/autoload_classmap.php');

        if ($statements === null) {
            return [];
        }

        // Entries are `$vendorDir . '/…'` concatenations rather than plain
        // strings, so the two variables the file defines are substituted here.
        return $this->entries($statements, [
            'vendorDir' => $vendorDirectory,
            'baseDir' => rtrim($projectRoot, '/'),
        ]);
    }

    /**
     * The file's syntax tree, or null when this run has no classmap to read.
     *
     * An absent classmap is legitimate and silent; a classmap that is present
     * and could not be used is not, so each way of failing names itself. Before
     * this they were all the same empty array, and a project whose classmap was
     * too large or unparsable measured as one that has no classmap at all.
     *
     * @return array<Node\Stmt>|null
     */
    private function statements(string $file): ?array
    {
        $size = is_file($file) ? filesize($file) : false;

        if ($size === false) {
            return null;
        }

        if ($size > self::MAX_BYTES) {
            $this->logger->warning(\sprintf(
                'Skipped "%s": %d bytes exceeds the %d-byte limit a run reads. '
                . 'Classes only this file places are treated as unplaced.',
                $file,
                $size,
                self::MAX_BYTES,
            ));

            return null;
        }

        $source = @file_get_contents($file);

        if ($source === false) {
            $this->logger->warning(\sprintf(
                'Cannot read "%s": the file exists but could not be opened. '
                . 'Classes only this file places are treated as unplaced.',
                $file,
            ));

            return null;
        }

        try {
            return (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
        } catch (Throwable $error) {
            $this->logger->warning(\sprintf(
                'Cannot parse "%s": %s. Classes only this file places are treated as unplaced.',
                $file,
                $error->getMessage(),
            ));

            return null;
        }
    }

    /**
     * The entries the tree holds, first spelling of a class name winning.
     *
     * Only string keys are entries: composer writes nothing else, and a shape
     * this cannot read is skipped rather than guessed at, exactly as an
     * unreadable value is.
     *
     * @param array<Node\Stmt> $statements
     * @param array<string, string> $variables
     *
     * @return array<string, string> FQCN => file
     */
    private function entries(array $statements, array $variables): array
    {
        $entries = [];

        /** @var list<Node\Expr\ArrayItem> $items */
        $items = (new NodeFinder())->findInstanceOf($statements, Node\Expr\ArrayItem::class);

        foreach ($items as $item) {
            if (!$item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $path = $this->path->resolve($item->value, $variables);

            if ($path !== null && !isset($entries[$item->key->value])) {
                $entries[$item->key->value] = $path;
            }
        }

        return $entries;
    }
}
