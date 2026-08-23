<?php

namespace CorpusHttp;

class Client
{
    private array $headers = [];
    private $transport;
    private int $retries = 0;

    public function send($body)
    {
        if ($body === null) {
            return '';
        }
        if ($this->transport !== null) {
            return ($this->transport)($body);
        }
        foreach ($this->headers as $name => $value) {
            if ($name === 'x-retry' && $value === 'on') {
                ++$this->retries;
            } elseif ($name === 'x-abort') {
                return '';
            }
        }

        return is_scalar($body) ? (string) $body : '';
    }

    public function retries(): int
    {
        return $this->retries;
    }
}
