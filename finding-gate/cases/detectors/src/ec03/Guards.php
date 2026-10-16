<?php
namespace App;
final class Guards
{
    /** guard whose condition calls, continue, work after: not a chain */
    public function guardCallContinue(array $items): void
    {
        foreach ($items as $x) {
            try {
                if (!$this->supports($x)) {
                    continue;
                }
                $this->work($x);
            } catch (\Throwable $e) {
            }
            $this->after($x);
        }
    }
    /** guard whose condition calls, return, work after: residual (same form as a chain whose success test is a call) */
    public function guardCallReturn(array $items): void
    {
        foreach ($items as $x) {
            try {
                if (!$this->supports($x)) {
                    return;
                }
                $this->work($x);
            } catch (\Throwable $e) {
            }
        }
    }
    /** earlier call, then guard, then work: residual (same form as json-schema AnyOf) */
    public function precededGuard(array $items): void
    {
        foreach ($items as $x) {
            try {
                $y = $this->prepare($x);
                if ($y === null) {
                    continue;
                }
                $this->work($y);
            } catch (\Throwable $e) {
            }
            $this->after($x);
        }
    }
    /** primary then fallback: continue skips the fallback on success */
    public function primaryFallback(array $items): void
    {
        foreach ($items as $x) {
            try {
                $this->work($x);
                continue;
            } catch (\Throwable $e) {
            }
            $this->after($x);
        }
    }
    private function supports(mixed $x): bool { return true; }
    private function prepare(mixed $x): mixed { return $x; }
    private function work(mixed $x): mixed { return $x; }
    private function after(mixed $x): void {}
}
