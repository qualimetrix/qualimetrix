<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\SelectorYamlDecoder;
use Qualimetrix\Core\Path\RelativePath;

#[CoversClass(SelectorYamlDecoder::class)]
final class SelectorYamlDecoderTest extends TestCase
{
    #[Test]
    public function itBuildsAPathPatternFromAnExplicitYamlMapping(): void
    {
        $pattern = $this->decoder()->decodePath(
            ['subtree' => 'src/Generated'],
            self::origin(),
            ['suppress_paths', '0'],
        );

        self::assertSame('subtree:src/Generated', $pattern->definition->display());
        self::assertTrue($pattern->matches(RelativePath::fromString('src/Generated/Proxy.php')));
    }

    #[Test]
    public function itBuildsANamespacePatternFromAnExplicitYamlMapping(): void
    {
        $pattern = $this->decoder()->decodeNamespace(
            ['regex' => 'App\\\\(?:Entity|Dto)(?:\\\\[^\\\\]+)*'],
            self::origin(),
            ['rules', 'design.data-class', 'suppress_namespaces', '1'],
        );

        self::assertSame('regex:App\\\\(?:Entity|Dto)(?:\\\\[^\\\\]+)*', $pattern->definition->display());
        self::assertTrue($pattern->matches('App\\Entity\\Internal'));
        self::assertFalse($pattern->matches('App\\Service'));
    }

    #[Test]
    #[DataProvider('provideMalformedEntries')]
    public function itRefusesEveryMalformedYamlSelectorEntry(mixed $entry, string $message): void
    {
        try {
            $this->decoder()->decodePath($entry, self::origin(), ['suppress_paths', '3']);
            self::fail('Expected the malformed selector to be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->origin()->source());
            self::assertSame('/project/qmx.yaml', $refusal->origin()->locator());
            self::assertStringContainsString($message, $refusal->summary());
            self::assertNotNull($refusal->position());
            self::assertStringStartsWith('suppress_paths.3', $refusal->position()->display());
        }
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function provideMalformedEntries(): iterable
    {
        yield 'bare string' => ['src/Generated', 'one-entry mappings'];
        yield 'empty mapping' => [[], 'one-entry mappings'];
        yield 'multiple kinds' => [['exact' => 'src/A.php', 'regex' => 'src/.+'], 'one-entry mappings'];
        yield 'unknown kind' => [['glob' => 'src/*'], 'Unknown selector kind "glob"'];
        yield 'non-string value' => [['exact' => 42], 'must have a non-empty string value'];
        yield 'empty value' => [['regex' => ''], 'must have a non-empty string value'];
    }

    #[Test]
    public function itAttachesCoreValidationFailureToTheAuthoredSelectorKind(): void
    {
        try {
            $this->decoder()->decodeNamespace(
                ['regex' => '('],
                self::origin(),
                ['suppress_namespaces', '0'],
            );
            self::fail('Expected invalid PCRE to be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('not valid PCRE', $refusal->summary());
            self::assertSame('suppress_namespaces.0.regex', $refusal->position()?->display());
            self::assertNotNull($refusal->getPrevious());
        }
    }

    private function decoder(): SelectorYamlDecoder
    {
        return new SelectorYamlDecoder();
    }

    private static function origin(): ConfigurationOrigin
    {
        return ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/project/qmx.yaml');
    }
}
