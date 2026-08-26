<?php

namespace Corpus\Complexity;

function evaluateBatch(array $items, int $mode, string $rounding): int
{
    $total = 0;
    $count = count($items);
    for ($i = 0; $i < $count; $i++) {
        $item = $items[$i];
        switch ($mode) {
            case 1:
                if ($item > 0 && $rounding === 'strict') {
                    $total += $item * 2;
                } elseif ($item > 0) {
                    $total += $item;
                }
                break;
            case 2:
                try {
                    $total += 100 / $item;
                } catch (\DivisionByZeroError $e) {
                    $total -= 1;
                }
                break;
            default:
                if ($rounding === 'strict') {
                    $total++;
                }
        }
    }

    for ($j = 0; $j < $total; $j++) {
        if ($j % 2 === 0) {
            $total--;
        } elseif ($j % 3 === 0) {
            $total -= 2;
        } else {
            continue;
        }
    }

    return $total > 0 ? $total : 0;
}

class Router
{
    public function route(string $method, string $path, array $flags): string
    {
        if ($method === 'GET') {
            if ($path === '/') {
                return 'home';
            }
            if ($path === '/users') {
                return isset($flags['admin']) ? 'admin-users' : 'users';
            }
            foreach ($flags as $key => $value) {
                if ($key === 'legacy' && $value) {
                    return 'legacy';
                }
                if ($key === 'beta' || $value === 'beta') {
                    return 'beta';
                }
            }
        } elseif ($method === 'POST') {
            $remaining = count($flags);
            while ($remaining > 0) {
                --$remaining;
                $flag = array_pop($flags);
                if ($flag === 'stop') {
                    break;
                }
                if ($flag === 'skip') {
                    continue;
                }
            }
            return match ($path) {
                '/users' => 'create-user',
                '/posts' => 'create-post',
                default => 'create',
            };
        }

        return $path !== '' ? ($method === 'DELETE' ? 'delete' : 'unknown') : 'root';
    }

    public function normalise(string $path, string $trailing, string $lower): string
    {
        $result = $lower === 'yes' ? strtolower($path) : $path;
        if ($trailing === 'yes' && !str_ends_with($result, '/')) {
            $result .= '/';
        } elseif ($trailing !== 'yes' && str_ends_with($result, '/')) {
            $result = rtrim($result, '/');
        }

        return $result === '' ? '/' : $result;
    }
}
