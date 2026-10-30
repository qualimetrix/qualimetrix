<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(ConfigurationDocument::class)]
final class ConfigurationDocumentRegularFileTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/qmx-document-file-' . bin2hex(random_bytes(8));
        mkdir($this->dir . '/sub', 0o777, true);
        file_put_contents($this->dir . '/base.yaml', '');
        file_put_contents($this->dir . '/other.yaml', '');
        symlink($this->dir . '/base.yaml', $this->dir . '/link.yaml');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function itNamesTheFileAPathLeadsTo(): void
    {
        $document = $this->document();

        self::assertSame('configuration file "base.yaml"', $document->regularFileReadAs($this->dir . '/base.yaml'));
        self::assertSame('configuration file "base.yaml"', $document->regularFileReadAs($this->dir . '/link.yaml'), 'a link leads to the file it names');
    }

    #[Test]
    public function itNamesNothingForAPathThatLeadsToNoFileItRead(): void
    {
        $document = $this->document();

        self::assertNull($document->regularFileReadAs($this->dir . '/other.yaml'));
        self::assertNull($document->regularFileReadAs($this->dir . '/missing.yaml'));
        self::assertNull($document->regularFileReadAs($this->dir . '/sub'));
    }

    private function document(): ConfigurationDocument
    {
        $stat = stat($this->dir . '/base.yaml');
        self::assertNotFalse($stat);

        return new ConfigurationDocument(
            [['source' => 'base.yaml', 'values' => [], 'file' => FileIdentity::fromStat($stat), 'name' => 'configuration file "base.yaml"']],
            AbsolutePath::fromString($this->dir),
        );
    }
}
