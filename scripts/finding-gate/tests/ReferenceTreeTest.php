<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Fs;
use QmxFindingGate\ReferenceTree;
use ReflectionClass;

final class ReferenceTreeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itComparesNativeLockFactsExceptRootContentHash(): void
    {
        $candidate = Fs::temporaryDirectory('lock-candidate-');
        $reference = Fs::temporaryDirectory('lock-reference-');
        $reflection = new ReflectionClass(ReferenceTree::class);
        $tree = $reflection->newInstanceWithoutConstructor();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke($tree, $reference, $candidate, $reference);
        $lock = '{"content-hash":"before","packages":[{"name":"vendor/package","version":"1.0","content-hash":"package-hash"}],"platform":{"php":"^8.4","composer-runtime-api":"^2.2"},"flags":[1,2],"value":1,"shape":{},"numeric-keys":{"1":"one","01":"zero-one"}}';
        try {
            Fs::write($candidate . '/composer.lock', $lock);
            Fs::write($reference . '/composer.lock', $lock);
            self::assertNull($tree->dependencySetMismatch());
            foreach ([
                'root content hash' => str_replace('"before"', '"after"', $lock),
                'object key order' => str_replace('"php":"^8.4","composer-runtime-api":"^2.2"', '"composer-runtime-api":"^2.2","php":"^8.4"', $lock),
                'numeric object key order' => str_replace('"1":"one","01":"zero-one"', '"01":"zero-one","1":"one"', $lock),
                'both irrelevant differences' => str_replace(['"before"', '"php":"^8.4","composer-runtime-api":"^2.2"'], ['"after"', '"composer-runtime-api":"^2.2","php":"^8.4"'], $lock),
            ] as $case => $contents) {
                Fs::write($reference . '/composer.lock', $contents);
                self::assertNull($tree->dependencySetMismatch(), $case);
            }
            foreach ([
                'runtime requirement' => str_replace('"^2.2"', '"^2.3"', $lock),
                'package version' => str_replace('"1.0"', '"2.0"', $lock),
                'nested content hash' => str_replace('"package-hash"', '"different"', $lock),
                'list order' => str_replace('[1,2]', '[2,1]', $lock),
                'integer versus float' => str_replace('"value":1', '"value":1.0', $lock),
                'integer versus string' => str_replace('"value":1', '"value":"1"', $lock),
                'integer versus boolean' => str_replace('"value":1', '"value":true', $lock),
                'integer versus null' => str_replace('"value":1', '"value":null', $lock),
                'object versus list' => str_replace('"shape":{}', '"shape":[]', $lock),
                'object member removed' => str_replace(',"shape":{}', '', $lock),
                'malformed JSON' => '{',
                'non-object JSON' => '[]',
            ] as $case => $contents) {
                Fs::write($reference . '/composer.lock', $contents);
                self::assertNotNull($tree->dependencySetMismatch(), $case);
                Fs::write($reference . '/composer.lock', $lock);
                Fs::write($candidate . '/composer.lock', $contents);
                self::assertNotNull($tree->dependencySetMismatch(), 'candidate ' . $case);
                Fs::write($candidate . '/composer.lock', $lock);
            }
            foreach (['{', '[]'] as $contents) {
                Fs::write($candidate . '/composer.lock', $contents);
                Fs::write($reference . '/composer.lock', $contents);
                self::assertNotNull($tree->dependencySetMismatch(), 'both invalid: ' . $contents);
            }
        } finally {
            Fs::removeRecursively($candidate);
            Fs::removeRecursively($reference);
        }
    }
}
