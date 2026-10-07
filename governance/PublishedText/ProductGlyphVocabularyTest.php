<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\PublishedText;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Formatter\Prose\AsciiGlyphs;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Native literal values only; runtime-generated glyphs and source data are outside this census. */
final class ProductGlyphVocabularyTest extends TestCase
{
    #[Test]
    public function everyNonAsciiSourceLiteralHasAnExaminedGlyph(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $unknown = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, $file->getPathname());
            $nodes = $parser->parse($source) ?? [];
            $inputCharacters = [];
            if ($file->getPathname() === \dirname(__DIR__, 2) . '/src/Analysis/Policy/Inline/Contract/SuppressionSyntax.php') {
                foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassConst::class) as $declaration) {
                    foreach ($declaration->consts as $constant) {
                        if ($constant->name->toString() !== 'SPACE_NAMES' || !$constant->value instanceof Node\Expr\Array_) {
                            continue;
                        }
                        foreach ($constant->value->items as $item) {
                            if ($item instanceof Node\ArrayItem && $item->key instanceof Node\Scalar\String_) {
                                $inputCharacters[spl_object_id($item->key)] = true;
                            }
                        }
                    }
                }
            }
            $literals = $finder->find($nodes, static fn(Node $node): bool => $node instanceof Node\Scalar\String_ || $node instanceof Node\InterpolatedStringPart);
            foreach ($literals as $literal) {
                if (!$literal instanceof Node\Scalar\String_ && !$literal instanceof Node\InterpolatedStringPart) {
                    continue;
                }
                if (isset($inputCharacters[spl_object_id($literal)])) {
                    continue;
                }
                foreach (mb_str_split($literal->value, 1, 'UTF-8') as $glyph) {
                    if (!mb_check_encoding($glyph, 'ASCII') && !\array_key_exists($glyph, AsciiGlyphs::REPLACEMENTS)) {
                        $unknown[$file->getPathname() . ':' . $literal->getStartLine() . ':' . bin2hex($glyph)] = $glyph;
                    }
                }
            }
        }
        self::assertSame([], $unknown, 'A new literal product glyph needs an ASCII publication decision.');
    }
}
