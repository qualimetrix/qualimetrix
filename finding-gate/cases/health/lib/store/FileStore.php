<?php

namespace CorpusStore;

class FileStore
{
    private array $written = [];
    private string $root = '.';

    public function write($key, $value)
    {
        if (!is_string($key)) {
            return '';
        }
        $this->written[$key] = $value;

        return $this->root . '/' . $key;
    }

    public function count(): int
    {
        return count($this->written);
    }
}
