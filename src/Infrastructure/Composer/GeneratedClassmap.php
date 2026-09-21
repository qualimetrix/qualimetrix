<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
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

    public function __construct(private ClassmapPath $path = new ClassmapPath()) {}

    /**
     * @return array<string, string> FQCN => file
     */
    public function read(string $projectRoot, string $vendorDirectory): array
    {
        $file = rtrim($vendorDirectory, '/') . '/composer/autoload_classmap.php';

        $size = is_file($file) ? filesize($file) : false;

        if ($size === false || $size > self::MAX_BYTES) {
            return [];
        }

        $source = @file_get_contents($file);

        if ($source === false) {
            return [];
        }

        try {
            $ast = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
        } catch (Throwable) {
            return [];
        }

        // Entries are `$vendorDir . '/…'` concatenations rather than plain
        // strings, so the two variables the file defines are substituted here.
        $variables = [
            'vendorDir' => rtrim($vendorDirectory, '/'),
            'baseDir' => rtrim($projectRoot, '/'),
        ];

        $entries = [];

        /** @var list<Node\Expr\ArrayItem> $items */
        $items = (new NodeFinder())->findInstanceOf($ast, Node\Expr\ArrayItem::class);

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
