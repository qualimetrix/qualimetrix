<?php

declare(strict_types=1);

use Qualimetrix\Tests\TestSupport\WorkingDirectory\SuiteWorkingDirectoryExtension;

require_once __DIR__ . '/../vendor/autoload.php';

// Isolated PHPUnit children execute from stdin and share the parent's root.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'phpunit') {
    $processRoot = sys_get_temp_dir() . '/qmx-suite-' . bin2hex(random_bytes(8));
    if (!mkdir($processRoot, 0o700)) {
        throw new RuntimeException('Cannot create PHPUnit process root: ' . $processRoot);
    }
    $resolvedRoot = realpath($processRoot);
    if ($resolvedRoot === false) {
        throw new RuntimeException('Cannot resolve PHPUnit process root');
    }
    $processRoot = $resolvedRoot;
    define('QMX_TEST_PROCESS_ROOT', $processRoot);
    putenv('QMX_TEST_PROCESS_ROOT=' . $processRoot);
    $ownerPid = getmypid();
    register_shutdown_function(static function () use ($processRoot, $ownerPid): void {
        if (getmypid() === $ownerPid) {
            SuiteWorkingDirectoryExtension::finishProcess($processRoot);
        }
    });
} elseif (!defined('QMX_TEST_PROCESS_ROOT')) {
    $processRoot = getenv('QMX_TEST_PROCESS_ROOT');
    if ($processRoot === false || !is_dir($processRoot)) {
        throw new RuntimeException('Isolated PHPUnit child has no parent process root');
    }
    define('QMX_TEST_PROCESS_ROOT', $processRoot);
}
