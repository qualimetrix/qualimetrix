<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\AuthoredShape;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;

/**
 * The root configuration file and every file reachable through `imports`, as
 * the layers the composer merges: the C3 linearization of the import graph
 * ({@see ImportLinearization}), lowest precedence first, the root last. Any
 * file read through `imports` may import in turn.
 *
 * One depth-first walk reads each file once and names it by the first chain
 * that reaches it; an entry that leads back to a file still being walked is
 * refused where it is written, naming the cycle. The walk's state lives in
 * the properties below and is reset by every {@see expand()}.
 */
final class ImportChain
{
    /** @var array<string, AuthoredLayer> file key => its layer without `imports`, for every file reached */
    private array $layers = [];

    /** @var array<string, list<list<string>>> file key => its entries as groups of keys, for every file finished */
    private array $groups = [];

    /** @var array<string, ConfigurationOrigin> */
    private array $origins = [];

    /** @var array<string, array<string, string>> importer key => imported key => `imports[N]` */
    private array $positions = [];

    private readonly ImportEntries $entries;

    public function __construct(
        private readonly ConfigLoaderInterface $loader,
    ) {
        $this->entries = new ImportEntries();
    }

    /**
     * @throws ConfigurationRefusal
     *
     * @return non-empty-list<AuthoredLayer> lowest precedence first; the root's own layer last; none keeps `imports`
     */
    public function expand(string $rootPhysicalPath, ConfigurationOrigin $rootOrigin): array
    {
        $this->layers = $this->groups = $this->origins = $this->positions = [];

        $loaded = $this->loader->read($rootPhysicalPath, $rootOrigin);
        $entries = self::entries($loaded->authored, $rootOrigin);
        if ($entries === []) {
            return [new AuthoredLayer($rootOrigin, self::withoutImports($loaded->authored), file: $loaded->file)];
        }

        $root = ImportTarget::ofFile($rootPhysicalPath, $rootOrigin);
        $this->admit($root, $loaded);
        $this->visit($root, $entries, [$root->key => $rootOrigin]);

        $order = ImportLinearization::of(
            $root->key,
            $this->groups,
            fn(string $key): ConfigurationOrigin => $this->origins[$key],
            fn(string $importer, string $imported): string => $this->positions[$importer][$imported],
        );

        return [...array_map(fn(string $key): AuthoredLayer => $this->layers[$key], \array_slice($order, 0, -1)), $this->layers[$root->key]];
    }

    /**
     * @param list<ResolvedValueInterface> $entries
     * @param array<string, ConfigurationOrigin> $stack the files being walked, root first
     *
     * @throws ConfigurationRefusal
     */
    private function visit(ImportTarget $file, array $entries, array $stack): void
    {
        $from = $this->importingFile($file);
        $groups = [];
        foreach ($this->entries->resolve($entries, $from) as $entry) {
            $keys = [];
            foreach ($entry->targets as $target) {
                if (isset($stack[$target->key])) {
                    $this->refuseCycle($entry, $target, $from, $stack);
                }
                $keys[] = $target->key;
                $this->positions[$file->key][$target->key] = \sprintf('imports[%d]', $entry->position);
                if (!isset($this->layers[$target->key])) {
                    $this->descend($target, $stack);
                }
            }
            if ($keys !== []) {
                $groups[] = $keys;
            }
        }
        $this->groups[$file->key] = $groups;
    }

    /**
     * @param array<string, ConfigurationOrigin> $stack
     *
     * @throws ConfigurationRefusal
     */
    private function descend(ImportTarget $target, array $stack): void
    {
        $loaded = $this->loader->read($target->physicalPath, $target->origin);
        $this->admit($target, $loaded);
        $this->visit($target, self::entries($loaded->authored, $target->origin), $stack + [$target->key => $target->origin]);
    }

    private function admit(ImportTarget $file, LoadedDocument $loaded): void
    {
        $this->origins[$file->key] = $file->origin;
        $this->layers[$file->key] = new AuthoredLayer($file->origin, self::withoutImports($loaded->authored), file: $loaded->file);
    }

    /**
     * @param array<string, ConfigurationOrigin> $stack
     *
     * @throws ConfigurationRefusal
     */
    private function refuseCycle(ResolvedImportEntry $entry, ImportTarget $target, ImportingFile $from, array $stack): never
    {
        $cycle = [];
        foreach ($stack as $key => $origin) {
            if ($key === $target->key || $cycle !== []) {
                $cycle[] = self::label($origin);
            }
        }
        $cycle[] = self::label($target->origin);

        $entry->value->refuse(\sprintf(
            'Entry imports[%d] ("%s") of %s closes an import cycle: %s.',
            $entry->position,
            (string) $entry->value->plain(),
            $from->origin->describe(),
            implode(' imports ', $cycle),
        ));
    }

    private static function label(ConfigurationOrigin $origin): string
    {
        return '"' . ($origin->locator() ?? $origin->describe()) . '"';
    }

    /**
     * Each file names its entries from its own directory: the directory of
     * its name, or its real directory when the file is a symlink or has no
     * path of its own (a built-in preset).
     *
     * @throws ConfigurationRefusal
     */
    private function importingFile(ImportTarget $file): ImportingFile
    {
        $directory = $file->realDirectory();
        $named = $file->isSymlink || $file->origin->source() === ConfigurationSource::Preset
            ? $directory
            : \dirname($file->origin->locator() ?? '.');

        return new ImportingFile($file->origin, $directory, $named, $file->key);
    }

    /**
     * Whether the document's root writes `imports`. The key has one accepted
     * spelling, so the literal key is the only one to look for; any other
     * spelling stays in place for the composer to refuse.
     */
    private static function writesImports(AuthoredNode $document): bool
    {
        return $document->shape === AuthoredShape::Mapping && \array_key_exists(ConfigurationRoot::Imports->value, $document->children);
    }

    /**
     * The entries a file wrote, judged by the declaration's own grammar on
     * a one-key slice of it, so nothing else in the file is read here.
     *
     * @throws ConfigurationRefusal
     *
     * @return list<ResolvedValueInterface>
     */
    private static function entries(AuthoredNode $document, ConfigurationOrigin $origin): array
    {
        if (!self::writesImports($document)) {
            return [];
        }

        $key = ConfigurationRoot::Imports->value;
        $list = DocumentComposer::compose(new DocumentSchema([ConfigurationRoot::Imports]), [
            new AuthoredLayer($origin, AuthoredNode::mapping([$key => $document->children[$key]], $document->line, $document->locator, $document->authoredExpression)),
        ])->get($key);

        return $list instanceof ResolvedListInterface ? $list->items() : [];
    }

    private static function withoutImports(AuthoredNode $document): AuthoredNode
    {
        if (!self::writesImports($document)) {
            return $document;
        }

        $children = $document->children;
        unset($children[ConfigurationRoot::Imports->value]);

        return AuthoredNode::mapping(
            array_combine(array_map('strval', array_keys($children)), $children),
            $document->line,
            $document->locator,
            $document->authoredExpression,
        );
    }
}
