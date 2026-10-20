<?php

declare(strict_types=1);

namespace QmxFindingGate {
    /** @phpstan-impure */
    function is_dir(string $path): bool
    {
        return \QmxFindingGate\Tests\FsNativeProbe::isDirectory($path);
    }

    /** @return list<string>|false */
    function scandir(string $path): array|false
    {
        return \QmxFindingGate\Tests\FsNativeProbe::scanDirectory($path);
    }

    function readlink(string $path): string|false
    {
        return \QmxFindingGate\Tests\FsNativeProbe::readLink($path);
    }

    function unlink(string $path): bool
    {
        return \QmxFindingGate\Tests\FsNativeProbe::unlink($path);
    }

    function rmdir(string $path): bool
    {
        return \QmxFindingGate\Tests\FsNativeProbe::removeDirectory($path);
    }
}

namespace QmxFindingGateControls {
    /** @phpstan-impure */
    function is_dir(string $path): bool
    {
        return \QmxFindingGate\Tests\FsNativeProbe::isDirectory($path);
    }

    /** @return list<string>|false */
    function scandir(string $path): array|false
    {
        return \QmxFindingGate\Tests\FsNativeProbe::scanDirectory($path);
    }

    function readlink(string $path): string|false
    {
        return \QmxFindingGate\Tests\FsNativeProbe::readLink($path);
    }

    function unlink(string $path): bool
    {
        return \QmxFindingGate\Tests\FsNativeProbe::unlink($path);
    }

    function rmdir(string $path): bool
    {
        return \QmxFindingGate\Tests\FsNativeProbe::removeDirectory($path);
    }
}
