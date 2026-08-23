<?php

namespace Corpus\Complexity;

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
