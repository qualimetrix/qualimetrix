<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Strategy;

/** The process's group and its ancestors inside the corresponding kernel mount. */
final readonly class CgroupLocation
{
    public function __construct(private string $systemRoot) {}

    /** @return list<string> */
    public function ancestors(string $controller): array
    {
        [$mount, $mountRoot] = $this->mount($controller);
        $directory = $this->mountedGroup($mount, $mountRoot, $this->groupPath($controller));
        $ancestors = [];
        while ($directory !== $mount && str_starts_with($directory, $mount . '/')) {
            $ancestors[] = $directory;
            $directory = \dirname($directory);
        }
        $ancestors[] = $mount;

        return $ancestors;
    }

    private function mountedGroup(string $mount, string $mountRoot, string $group): string
    {
        if ($mountRoot !== '/' && ($group === $mountRoot || str_starts_with($group, $mountRoot . '/'))) {
            $group = substr($group, \strlen($mountRoot));
        }
        $directory = $mount . ($group === '/' ? '' : $group);
        if (!is_dir($this->systemRoot . $directory) || \in_array('..', explode('/', $group), true)) {
            return $mount;
        }

        return $directory;
    }

    private function groupPath(string $controller): string
    {
        foreach (explode("\n", $this->read('/proc/self/cgroup') ?? '') as $line) {
            $parts = explode(':', $line, 3);
            if (\count($parts) === 3 && \in_array($controller, explode(',', $parts[1]), true)) {
                return $parts[2];
            }
        }

        return '';
    }

    /** @return array{string, string} */
    private function mount(string $controller): array
    {
        foreach (explode("\n", $this->read('/proc/self/mountinfo') ?? '') as $line) {
            $halves = explode(' - ', $line, 2);
            $fields = explode(' ', $halves[0]);
            $type = explode(' ', $halves[1] ?? '');
            if (self::matchesMount($controller, $type) && isset($fields[3], $fields[4])) {
                return [stripcslashes($fields[4]), stripcslashes($fields[3])];
            }
        }

        return [$controller === '' ? '/sys/fs/cgroup' : '/sys/fs/cgroup/cpu', '/'];
    }

    /** @param list<string> $type */
    private static function matchesMount(string $controller, array $type): bool
    {
        return $controller === ''
            ? ($type[0] ?? '') === 'cgroup2'
            : ($type[0] ?? '') === 'cgroup' && \in_array($controller, explode(',', $type[2] ?? ''), true);
    }

    private function read(string $path): ?string
    {
        $contents = @file_get_contents($this->systemRoot . $path);

        return $contents === false ? null : $contents;
    }
}
