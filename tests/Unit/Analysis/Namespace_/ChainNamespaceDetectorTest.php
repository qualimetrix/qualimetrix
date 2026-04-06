<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Namespace_;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Namespace_\ChainNamespaceDetector;
use Qualimetrix\Core\Namespace_\NamespaceDetectorInterface;
use RuntimeException;
use SplFileInfo;

#[CoversClass(ChainNamespaceDetector::class)]
final class ChainNamespaceDetectorTest extends TestCase
{
    #[Test]
    public function itReturnsFirstNonEmptyResult(): void
    {
        $first = self::createStub(NamespaceDetectorInterface::class);
        $first->method('detect')->willReturn('');

        $second = self::createStub(NamespaceDetectorInterface::class);
        $second->method('detect')->willReturn('App\\Service');

        $third = $this->createMock(NamespaceDetectorInterface::class);
        $third->expects(self::never())->method('detect');

        $chain = new ChainNamespaceDetector([$first, $second, $third]);

        self::assertSame('App\\Service', $chain->detect(new SplFileInfo(__FILE__)));
    }

    #[Test]
    public function itReturnsEmptyWhenAllDetectorsReturnEmpty(): void
    {
        $first = self::createStub(NamespaceDetectorInterface::class);
        $first->method('detect')->willReturn('');

        $second = self::createStub(NamespaceDetectorInterface::class);
        $second->method('detect')->willReturn('');

        $chain = new ChainNamespaceDetector([$first, $second]);

        self::assertSame('', $chain->detect(new SplFileInfo(__FILE__)));
    }

    #[Test]
    public function itIgnoresExceptionsFromDetectors(): void
    {
        $throwing = self::createStub(NamespaceDetectorInterface::class);
        $throwing->method('detect')->willThrowException(new RuntimeException('Test'));

        $working = self::createStub(NamespaceDetectorInterface::class);
        $working->method('detect')->willReturn('App\\Domain');

        $chain = new ChainNamespaceDetector([$throwing, $working]);

        self::assertSame('App\\Domain', $chain->detect(new SplFileInfo(__FILE__)));
    }

    #[Test]
    public function itWorksWithEmptyDetectorsList(): void
    {
        $chain = new ChainNamespaceDetector([]);

        self::assertSame('', $chain->detect(new SplFileInfo(__FILE__)));
    }

    #[Test]
    public function itAcceptsIterableOfDetectors(): void
    {
        $detector = self::createStub(NamespaceDetectorInterface::class);
        $detector->method('detect')->willReturn('App\\Test');

        $generator = (static function () use ($detector): Generator {
            yield $detector;
        })();

        $chain = new ChainNamespaceDetector($generator);

        self::assertSame('App\\Test', $chain->detect(new SplFileInfo(__FILE__)));
    }
}
