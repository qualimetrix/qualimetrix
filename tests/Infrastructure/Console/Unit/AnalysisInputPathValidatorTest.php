<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\AnalysisInputPathValidator;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

#[CoversClass(AnalysisInputPathValidator::class)]
final class AnalysisInputPathValidatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-input-path-validator-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $entries = scandir($this->directory);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->directory . '/' . $entry);
            }
        }
        rmdir($this->directory);
    }

    #[Test]
    public function itRefusesMissingAndExplicitNonPhpPathsInOneAnswer(): void
    {
        $text = $this->directory . '/notes.txt';
        file_put_contents($text, 'notes');

        try {
            (new AnalysisInputPathValidator())->validate(
                [AbsolutePath::fromString($this->directory . '/missing.php'), AbsolutePath::fromString($text)],
                $this->document(),
            );
            self::fail('Expected an input-path refusal.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString("path '{$this->directory}/missing.php' does not exist", $refusal->summary());
            self::assertStringContainsString("path '{$text}' is not a PHP file", $refusal->summary());
        }
    }

    #[Test]
    public function itUsesTheFinderCaseSensitivePhpGrammar(): void
    {
        $upperCase = $this->directory . '/Sample.PHP';
        file_put_contents($upperCase, '<?php');

        $this->expectException(ConfigurationRefusal::class);
        (new AnalysisInputPathValidator())->validate([AbsolutePath::fromString($upperCase)], $this->document());
    }

    #[Test]
    public function itLeavesDirectoriesAndLowercasePhpFilesForDiscovery(): void
    {
        $php = $this->directory . '/Sample.php';
        file_put_contents($php, '<?php');

        try {
            (new AnalysisInputPathValidator())->validate(
                [AbsolutePath::fromString($this->directory), AbsolutePath::fromString($php)],
                $this->document(),
            );
        } catch (ConfigurationRefusal $refusal) {
            self::fail('Valid paths must reach discovery: ' . $refusal->summary());
        }

        self::addToAssertionCount(1);
    }

    private function document(): ConfigurationDocument
    {
        return LayeredDocument::of(
            [['source' => 'test', 'values' => ['paths' => [$this->directory]]]],
            AbsolutePath::fromString($this->directory),
        );
    }
}
