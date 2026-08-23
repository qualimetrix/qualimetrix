<?php

namespace Corpus\Health\Support;

class Translator
{
    private array $messages = [];
    private string $locale = 'en';

    public function translate($key)
    {
        if ($key === null) {
            return '';
        }

        return $this->messages[$this->locale][$key] ?? (string) $key;
    }

    public function setLocale($locale): void
    {
        $this->locale = $locale;
    }
}
