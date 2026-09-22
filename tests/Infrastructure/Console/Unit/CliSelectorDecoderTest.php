<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\CliSelectorDecoder;

#[CoversClass(CliSelectorDecoder::class)]
final class CliSelectorDecoderTest extends TestCase
{
    #[Test]
    public function itBuildsAPathPatternAndSplitsOnlyAtTheFirstColon(): void
    {
        $pattern = (new CliSelectorDecoder())->decodePath('regex:src/(?:Api:V2|Web)/.+\\.php', '--suppress-path');

        self::assertSame('regex:src/(?:Api:V2|Web)/.+\\.php', $pattern->definition->display());
        self::assertTrue($pattern->matches(RelativePath::fromString('src/Api:V2/Controller.php')));
    }

    #[Test]
    public function itBuildsANamespaceSubtreePattern(): void
    {
        $pattern = (new CliSelectorDecoder())->decodeNamespace('subtree:App\\Entity', '--suppress-namespace');

        self::assertSame('subtree:App\\Entity', $pattern->definition->display());
        self::assertTrue($pattern->matches('App\\Entity\\Internal'));
    }

    #[Test]
    #[DataProvider('provideMalformedScalars')]
    public function itRefusesMalformedCliSelectorScalars(string $value, string $message): void
    {
        try {
            (new CliSelectorDecoder())->decodePath($value, '--suppress-path');
            self::fail('Expected the malformed selector to be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(ConfigurationSource::CommandLine, $refusal->origin()->source());
            self::assertSame('--suppress-path', $refusal->origin()->locator());
            self::assertNull($refusal->position());
            self::assertStringContainsString($message, $refusal->summary());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideMalformedScalars(): iterable
    {
        yield 'bare string' => ['src/Generated', 'must use KIND:VALUE'];
        yield 'unknown kind' => ['glob:src/*', 'Unknown selector kind "glob"'];
        yield 'empty value' => ['exact:', 'must not be empty'];
        yield 'invalid pcre' => ['regex:(', 'not valid PCRE'];
    }
}
