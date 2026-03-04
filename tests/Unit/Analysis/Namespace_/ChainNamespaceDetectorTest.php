<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Namespace_;

use AiMessDetector\Analysis\Namespace_\ChainNamespaceDetector;
use AiMessDetector\Core\Namespace_\NamespaceDetectorInterface;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;

#[CoversClass(ChainNamespaceDetector::class)]
final class ChainNamespaceDetectorTest extends TestCase
{
    #[Test]
    public function itReturnsFirstNonEmptyResult(): void
    {
        $first = $this->createMock(NamespaceDetectorInterface::class);
        $first->method('detect')->willReturn('');

        $second = $this->createMock(NamespaceDetectorInterface::class);
        $second->method('detect')->willReturn('App\\Service');

        $third = $this->createMock(NamespaceDetectorInterface::class);
        $third->expects(self::never())->method('detect');

        $chain = new ChainNamespaceDetector([$first, $second, $third]);

        self::assertSame('App\\Service', $chain->detect(new SplFileInfo(__FILE__)));
    }

    #[Test]
    public function itReturnsEmptyWhenAllDetectorsReturnEmpty(): void
    {
        $first = $this->createMock(NamespaceDetectorInterface::class);
        $first->method('detect')->willReturn('');

        $second = $this->createMock(NamespaceDetectorInterface::class);
        $second->method('detect')->willReturn('');

        $chain = new ChainNamespaceDetector([$first, $second]);

        self::assertSame('', $chain->detect(new SplFileInfo(__FILE__)));
    }

    #[Test]
    public function itIgnoresExceptionsFromDetectors(): void
    {
        $throwing = $this->createMock(NamespaceDetectorInterface::class);
        $throwing->method('detect')->willThrowException(new RuntimeException('Test'));

        $working = $this->createMock(NamespaceDetectorInterface::class);
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
        $detector = $this->createMock(NamespaceDetectorInterface::class);
        $detector->method('detect')->willReturn('App\\Test');

        $generator = (static function () use ($detector): Generator {
            yield $detector;
        })();

        $chain = new ChainNamespaceDetector($generator);

        self::assertSame('App\\Test', $chain->detect(new SplFileInfo(__FILE__)));
    }
}
