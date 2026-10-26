#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates `qmx.schema.json`, the JSON Schema editors use to complete and
 * check a `qmx.yaml`, from the declarations the document engine composes
 * against: the sections the product container registers and the roots
 * Configuration declares. A section added to the product therefore reaches
 * the schema without an edit to this script.
 *
 * Usage:
 *   php scripts/generate-configuration-schema.php           # write
 *   php scripts/generate-configuration-schema.php --check   # 0 fresh, 1 drift, 2 cannot run
 *   ... --root=DIR   read and write qmx.schema.json under DIR instead of this
 *                    repository, so a control can prove on a copy that a
 *                    hand-edited schema is caught
 */

namespace Qualimetrix\ConfigurationSchema;

use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaJson;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaProjection;
use Throwable;

use function Qualimetrix\ConfigurationDeclarations\configurationSections;

require_once __DIR__ . '/configuration-declarations.php';

const ARTIFACT = 'qmx.schema.json';

function fail(string $message): never
{
    fwrite(\STDERR, 'generate-configuration-schema: ' . $message . "\n");

    exit(2);
}

/** @param list<string> $arguments */
function main(array $arguments): int
{
    $check = false;
    $root = \dirname(__DIR__);

    foreach ($arguments as $argument) {
        if ($argument === '--check') {
            $check = true;
        } elseif (str_starts_with($argument, '--root=')) {
            $root = substr($argument, \strlen('--root='));
        } else {
            fail(\sprintf('unknown argument "%s"', $argument));
        }
    }

    if (!is_dir($root)) {
        fail('no directory ' . $root);
    }

    try {
        $fresh = EditorSchemaJson::encode(EditorSchemaProjection::project((new DocumentSchema(configurationSections()))->root()));
    } catch (Throwable $error) {
        fail($error->getMessage());
    }

    $path = $root . '/' . ARTIFACT;

    if (!$check) {
        if (file_put_contents($path, $fresh) === false) {
            fail('cannot write ' . $path);
        }

        fwrite(\STDOUT, 'wrote ' . $path . "\n");

        return 0;
    }

    // A missing file is drift, not an inability to run: the generator can say what it should hold.
    $current = is_file($path) ? @file_get_contents($path) : null;
    if ($current === false) {
        fail('cannot read ' . $path);
    }

    if ($current !== $fresh) {
        fwrite(\STDERR, \sprintf(
            "%s is %s\nRun: php scripts/generate-configuration-schema.php\n",
            ARTIFACT,
            $current === null ? 'missing' : 'stale',
        ));

        return 1;
    }

    return 0;
}

exit(main(array_values(array_map(strval(...), \array_slice($_SERVER['argv'] ?? [], 1)))));
