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
    public function __construct(private ClassmapPath $path = new ClassmapPath()) {}

    /**
     * @return array<string, string> FQCN => file
     */
    public function read(string $vendorDirectory): array
    {
        $file = rtrim($vendorDirectory, '/') . '/composer/autoload_classmap.php';

        if (!is_file($file)) {
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
            'baseDir' => \dirname(rtrim($vendorDirectory, '/')),
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
