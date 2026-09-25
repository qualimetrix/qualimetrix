#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates the table of how each node of the configuration document combines
 * across layers — its merge policy, what `~` and an empty value mean there, and
 * the shorthands it accepts — and writes it into the configuration page, English
 * and Russian, between the two markers below.
 *
 * The table is read from the declarations the document engine itself composes
 * against: the sections the product container registers, completed with the
 * roots Configuration declares and a stand-in for every root nobody declares
 * yet. A section added to the product therefore appears here without an edit to
 * this script, and a root still carried unread appears as exactly that.
 *
 * Every sentence exists in both languages as an exhaustive `match`: a new merge
 * policy or scalar form stops the generator instead of leaving a cell empty.
 * Cells are padded the way `scripts/format-md-tables.py` pads them, so the
 * pre-commit hook leaves the generated block as written.
 *
 * Usage:
 *   php scripts/generate-configuration-merge-table.php           # write
 *   php scripts/generate-configuration-merge-table.php --check   # 0 fresh, 1 drift, 2 cannot run
 *   ... --root=DIR   read and write the two pages under DIR instead of this
 *                    repository, so a control can prove on a copy that a
 *                    hand-edited cell is caught
 */

namespace Qualimetrix\ConfigurationMergeTable;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;
use Qualimetrix\Analysis\Configuration\DocumentRoots;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ConfigurationConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

require __DIR__ . '/../vendor/autoload.php';

const PAGES = [
    'en' => 'website/docs/getting-started/configuration.md',
    'ru' => 'website/docs/getting-started/configuration.ru.md',
];

const BEGIN_MARKER = '<!-- generated:configuration-merge-table:begin (php scripts/generate-configuration-merge-table.php) -->';
const END_MARKER = '<!-- generated:configuration-merge-table:end -->';

/** A key the author writes under its parent map. */
const KEY = 'key';

/** A name under a map keyed by names: `computed_metrics.<name>`. */
const ENTRY = 'entry';

/** A shorthand key, standing for several keys of the same map. */
const SHORTHAND = 'shorthand';

const HEADERS = [
    'en' => ['columns' => ['Key', 'Value', 'Across layers', 'Written `~`', 'Written empty (`{}`, `[]`)']],
    'ru' => ['columns' => ['Ключ', 'Значение', 'Между слоями', 'Записано `~`', 'Записано пустым (`{}`, `[]`)']],
];

function fail(string $message): never
{
    fwrite(\STDERR, 'generate-configuration-merge-table: ' . $message . "\n");

    exit(2);
}

/**
 * Every root of the document the pipeline composes against, sorted by key: the
 * owner sections the container tags, completed exactly as the pipeline
 * completes them.
 *
 * @return list<DocumentSectionSchemaInterface>
 */
function sections(): array
{
    $container = (new ContainerFactory())->create();
    $owners = [];

    foreach (array_keys($container->findTaggedServiceIds(ConfigurationConfigurator::SECTION_TAG)) as $id) {
        // The pipeline inlines its sections, so none is left to fetch; a
        // section declares a schema and takes no collaborators.
        $class = $container->getDefinition($id)->getClass() ?? $id;
        $section = class_exists($class) ? new $class() : null;

        if (!$section instanceof DocumentSectionSchemaInterface) {
            fail(\sprintf('service "%s" carries the section tag but declares no section', $id));
        }

        $owners[] = $section;
    }

    if ($owners === []) {
        fail('the container tags no configuration section; the table would describe Configuration alone');
    }

    $sections = DocumentRoots::completing($owners);
    usort($sections, static fn(DocumentSectionSchemaInterface $a, DocumentSectionSchemaInterface $b): int => strcmp($a->key(), $b->key()));

    return $sections;
}

/**
 * One row per node an author writes: every key of a map, every named entry and
 * every shorthand, and the keys of a list item that is a map, under `<list>[]`.
 * An item's keys never merge — the list is taken whole from the layer that
 * wrote it — so their rows say what `~` and an empty value mean inside the item.
 *
 * @param list<array{path: string, node: NodeSchema, kind: string, shorthand: ?Shorthand, parent: string, inItem: bool}> $rows
 *
 * @return list<array{path: string, node: NodeSchema, kind: string, shorthand: ?Shorthand, parent: string, inItem: bool}>
 */
function walk(NodeSchema $node, string $path, string $kind, string $parent, array $rows, bool $inItem = false): array
{
    $rows[] = ['path' => $path, 'node' => $node, 'kind' => $kind, 'shorthand' => null, 'parent' => $parent, 'inItem' => $inItem];

    return below($node, $path, $rows, $inItem);
}

/**
 * The rows under a node: its keys and shorthands, its named entries, the keys
 * of its list items.
 *
 * @param list<array{path: string, node: NodeSchema, kind: string, shorthand: ?Shorthand, parent: string, inItem: bool}> $rows
 *
 * @return list<array{path: string, node: NodeSchema, kind: string, shorthand: ?Shorthand, parent: string, inItem: bool}>
 */
function below(NodeSchema $node, string $path, array $rows, bool $inItem): array
{
    if ($node->policy === MergePolicy::DeepMerge) {
        $fields = $node->fields();

        foreach ($fields as $key => $child) {
            $rows = walk($child, $path . '.' . $key, KEY, $path, $rows, $inItem);
        }

        foreach ($node->shorthands() as $shorthand) {
            $rows[] = [
                'path' => $path . '.' . $shorthand->key,
                'node' => $fields[$shorthand->targets[0]],
                'kind' => SHORTHAND,
                'shorthand' => $shorthand,
                'parent' => $path,
                'inItem' => $inItem,
            ];
        }
    }

    if ($node->policy === MergePolicy::ByName) {
        $rows = walk($node->element(), $path . '.<name>', ENTRY, $path, $rows, $inItem);
    }

    $isList = $node->policy === MergePolicy::Replace || $node->policy === MergePolicy::Accumulate;
    if ($isList && $node->element()->policy === MergePolicy::DeepMerge) {
        $rows = below($node->element(), $path . '[]', $rows, true);
    }

    return $rows;
}

function scalarForm(ScalarForm $form, string $language): string
{
    return match ($language) {
        'ru' => match ($form) {
            ScalarForm::String => 'строка',
            ScalarForm::Integer => 'целое число',
            ScalarForm::Number => 'число',
            ScalarForm::Boolean => 'boolean',
        },
        default => match ($form) {
            ScalarForm::String => 'string',
            ScalarForm::Integer => 'integer',
            ScalarForm::Number => 'number',
            ScalarForm::Boolean => 'boolean',
        },
    };
}

function scalar(NodeSchema $node, string $language): string
{
    $forms = array_map(static fn(ScalarForm $form): string => scalarForm($form, $language), $node->scalarForms());

    if ($forms === []) {
        return $language === 'ru' ? 'скаляр' : 'scalar';
    }

    return implode($language === 'ru' ? ' или ' : ' or ', $forms);
}

/** What one item of a list is. */
function item(NodeSchema $element, string $language): string
{
    return match ($element->policy) {
        MergePolicy::LastWriterWins => scalar($element, $language),
        MergePolicy::PerLayer => $language === 'ru' ? 'читает владелец' : 'read by its owner',
        MergePolicy::DeepMerge, MergePolicy::ByName => $language === 'ru' ? 'карта' : 'map',
        MergePolicy::Replace, MergePolicy::Accumulate => $language === 'ru' ? 'список' : 'list',
    };
}

/** The names a map keyed by names accepts. */
function vocabulary(?NameVocabulary $names, string $parent, string $language): string
{
    $ru = $language === 'ru';

    if ($names === null) {
        return $ru ? 'любое имя' : 'any name';
    }

    if ($names->isFromSibling()) {
        $sibling = '`' . ($parent === '' ? '' : $parent . '.') . $names->siblingKey . '`';

        return $ru
            ? \sprintf('имена сверяются с %s после слияния всех слоёв', $sibling)
            : \sprintf('names checked against %s once every layer is merged', $sibling);
    }

    if ($names->isPredicate()) {
        return $ru
            ? 'имя проверяется по грамматике секции в слое, который его записал'
            : 'a name judged by the section\'s grammar in the layer that wrote it';
    }

    $fixed = implode(', ', array_map(static fn(string $name): string => '`' . $name . '`', $names->fixedNames()));

    return ($ru ? 'одно из: ' : 'one of: ') . $fixed;
}

/** @param array{path: string, node: NodeSchema, kind: string, shorthand: ?Shorthand, parent: string, inItem: bool} $row */
function valueCell(array $row, string $language): string
{
    $node = $row['node'];
    $ru = $language === 'ru';

    return match ($node->policy) {
        MergePolicy::LastWriterWins => scalar($node, $language),
        MergePolicy::DeepMerge => $ru ? 'карта' : 'map',
        MergePolicy::Replace, MergePolicy::Accumulate => \sprintf($ru ? 'список (элемент: %s)' : 'list (item: %s)', item($node->element(), $language)),
        MergePolicy::ByName => \sprintf($ru ? 'карта по имени: %s' : 'map by name: %s', vocabulary($node->names(), dotParent($row['path']), $language)),
        MergePolicy::PerLayer => $ru ? 'читает владелец' : 'read by its owner',
    };
}

/** The path of the map a key stands in. */
function dotParent(string $path): string
{
    $at = strrpos($path, '.');

    return $at === false ? '' : substr($path, 0, $at);
}

function policy(MergePolicy $policy, string $language): string
{
    if ($language !== 'ru') {
        return $policy->describe();
    }

    return match ($policy) {
        MergePolicy::LastWriterWins => 'Побеждает последний слой, записавший значение.',
        MergePolicy::DeepMerge => 'Сливается по ключам; записанная пустая карта ничего не меняет.',
        MergePolicy::Replace => 'Последний записавший слой заменяет список целиком; пустой список тоже заменяет.',
        MergePolicy::Accumulate => 'Каждый слой добавляет свои элементы; повторы схлопываются.',
        MergePolicy::ByName => 'Сливается запись за записью по имени; каждая запись — по своей политике.',
        MergePolicy::PerLayer => 'Хранится по слоям без слияния; сворачивает владелец.',
    };
}

/** @param array{path: string, node: NodeSchema, kind: string, shorthand: ?Shorthand, parent: string, inItem: bool} $row */
function acrossCell(array $row, string $language): string
{
    $shorthand = $row['shorthand'];

    if ($row['inItem'] && $shorthand === null) {
        return $language === 'ru'
            ? 'Читается из слоя, записавшего список; между слоями не сливается.'
            : 'Read from the layer that wrote the list; never merged across layers.';
    }

    if ($shorthand === null) {
        return policy($row['node']->policy, $language);
    }

    $targets = implode($language === 'ru' ? ' и ' : ' and ', array_map(static fn(string $target): string => '`' . $target . '`', $shorthand->targets));

    return $language === 'ru'
        ? \sprintf('Сокращение для %s: раскрывается в слое, который его записал, до слияния; дальше каждый ключ сливается сам. Сокращение рядом с одним из них в том же слое — отказ.', $targets)
        : \sprintf('Shorthand for %s: expanded in the layer that wrote it, before any merge; each key then merges on its own. Writing it beside one of them in the same layer is refused.', $targets);
}

/** @param array{path: string, node: NodeSchema, kind: string, shorthand: ?Shorthand, parent: string, inItem: bool} $row */
function tildeCell(array $row, string $language): string
{
    $ru = $language === 'ru';
    $cell = match (true) {
        $row['inItem'] => $ru ? 'Не записано: как если бы ключа не было.' : 'Not written: as if the key were absent.',
        $row['kind'] === ENTRY => $ru ? 'Имя проверяется; тело остаётся за нижним слоем.' : 'The name is judged; the body stays with the layer below.',
        default => $ru ? 'Не записано: остаётся значение нижнего слоя.' : 'Not written: the layer below stands.',
    };

    $node = $row['node'];
    $isList = $node->policy === MergePolicy::Replace || $node->policy === MergePolicy::Accumulate;

    if ($isList && $node->element()->policy !== MergePolicy::PerLayer) {
        $cell .= $ru ? ' Элемент `~` — отказ.' : ' An item written `~` is refused.';
    }

    return $cell;
}

/** @param array{path: string, node: NodeSchema, kind: string, shorthand: ?Shorthand, parent: string, inItem: bool} $row */
function emptyCell(array $row, string $language): string
{
    $node = $row['node'];
    $ru = $language === 'ru';

    if ($row['inItem']) {
        return match ($node->policy) {
            MergePolicy::LastWriterWins => $ru ? 'Отказ: ожидается скаляр.' : 'Refused: a scalar is expected.',
            MergePolicy::DeepMerge, MergePolicy::ByName => $ru ? 'Ничего не записывает: как если бы ключа не было.' : 'Writes nothing: as if the key were absent.',
            MergePolicy::Replace, MergePolicy::Accumulate => $ru ? 'Пустой список.' : 'An empty list.',
            MergePolicy::PerLayer => $ru ? 'Решает владелец.' : 'Its owner decides.',
        };
    }

    return match ($node->policy) {
        MergePolicy::LastWriterWins => $ru ? 'Отказ: ожидается скаляр.' : 'Refused: a scalar is expected.',
        MergePolicy::DeepMerge, MergePolicy::ByName => $ru ? 'Ничего не меняет.' : 'Changes nothing.',
        MergePolicy::Replace => ($ru ? 'Заменяет нижний список пустым.' : 'Replaces the list below with an empty one.')
            . ($node->emptyOverrideNotice() === null
                ? ''
                : ($ru ? ' Если нижний список не пуст, выводится предупреждение.' : ' A warning says so when the list below was not empty.')),
        MergePolicy::Accumulate => $ru ? 'Ничего не добавляет.' : 'Adds nothing.',
        MergePolicy::PerLayer => $ru ? 'Решает владелец.' : 'Its owner decides.',
    };
}

/**
 * @param list<array{path: string, node: NodeSchema, kind: string, shorthand: ?Shorthand, parent: string, inItem: bool}> $rows
 */
function table(array $rows, string $language): string
{
    $lines = [HEADERS[$language === 'ru' ? 'ru' : 'en']['columns']];

    foreach ($rows as $row) {
        $lines[] = [
            '`' . $row['path'] . '`',
            valueCell($row, $language),
            acrossCell($row, $language),
            tildeCell($row, $language),
            emptyCell($row, $language),
        ];
    }

    return render($lines);
}

/**
 * Pads every cell to its column's width, the layout
 * `scripts/format-md-tables.py` produces.
 *
 * @param list<list<string>> $lines header first
 */
function render(array $lines): string
{
    $widths = array_fill(0, \count($lines[0]), 3);

    foreach ($lines as $cells) {
        foreach ($cells as $column => $cell) {
            if (str_contains($cell, '|')) {
                fail(\sprintf('cell "%s" holds a pipe, which would split the table row', $cell));
            }

            $widths[$column] = max($widths[$column], mb_strlen($cell));
        }
    }

    $row = static fn(array $cells): string => '| ' . implode(' | ', array_map(
        static fn(string $cell, int $width): string => $cell . str_repeat(' ', $width - mb_strlen($cell)),
        $cells,
        $widths,
    )) . ' |';

    $out = [$row($lines[0]), '| ' . implode(' | ', array_map(static fn(int $width): string => str_repeat('-', $width), $widths)) . ' |'];

    foreach (\array_slice($lines, 1) as $cells) {
        $out[] = $row($cells);
    }

    return implode("\n", $out);
}

/** The page with the block between the markers replaced by `$table`. */
function embed(string $page, string $table, string $path): string
{
    if (substr_count($page, BEGIN_MARKER) !== 1 || substr_count($page, END_MARKER) !== 1) {
        fail(\sprintf('%s must hold each marker exactly once: %s ... %s', $path, BEGIN_MARKER, END_MARKER));
    }

    $begin = strpos($page, BEGIN_MARKER);
    $end = strpos($page, END_MARKER);

    if ($begin === false || $end === false || $end < $begin) {
        fail(\sprintf('%s holds the end marker before the begin marker', $path));
    }

    $head = substr($page, 0, $begin + \strlen(BEGIN_MARKER));

    return $head . "\n\n" . $table . "\n\n" . substr($page, $end);
}

/** @param list<string> $arguments the command line after the script name */
function main(array $arguments): int
{
    $check = \in_array('--check', $arguments, true);
    $root = \dirname(__DIR__);

    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, \strlen('--root='));
        } elseif ($argument !== '--check') {
            fail(\sprintf('unknown argument "%s"', $argument));
        }
    }

    $rows = [];
    foreach (sections() as $section) {
        $rows = walk($section->schema(), $section->key(), KEY, '', $rows);
    }

    // Every page is read and embedded before any is written, so a page that
    // cannot take the table leaves the other one untouched.
    $stale = [];
    $writes = [];
    foreach (PAGES as $language => $relative) {
        $path = $root . '/' . $relative;
        $page = @file_get_contents($path);

        if ($page === false) {
            fail('cannot read ' . $path);
        }

        $fresh = embed($page, table($rows, $language), $relative);

        if ($fresh !== $page) {
            $stale[] = $relative;
            $writes[$path] = $fresh;
        }
    }

    if (!$check) {
        foreach ($writes as $path => $fresh) {
            if (file_put_contents($path, $fresh) === false) {
                fail('cannot write ' . $path);
            }

            fwrite(\STDOUT, 'wrote ' . $path . "\n");
        }

        return 0;
    }

    if ($stale !== []) {
        fwrite(\STDERR, \sprintf(
            "The configuration merge table is stale in: %s\nRun: php scripts/generate-configuration-merge-table.php\n",
            implode(', ', $stale),
        ));

        return 1;
    }

    return 0;
}

exit(main(array_values(array_map(strval(...), \array_slice($_SERVER['argv'] ?? [], 1)))));
