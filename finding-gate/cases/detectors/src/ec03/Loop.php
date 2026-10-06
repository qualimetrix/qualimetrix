<?php
namespace App;
final class Loop
{
    /** residual shape: guard continue before work, statement after try */
    public function guardThenWork(array $items): void
    {
        foreach ($items as $x) {
            try {
                if ($x === null) {
                    continue;
                }
                $this->work($x);
            } catch (\Throwable $e) {
            }
            $this->after($x);
        }
    }
    /** legitimate chain: return on success */
    public function chainReturn(array $items): mixed
    {
        foreach ($items as $x) {
            try {
                return $this->work($x);
            } catch (\Throwable $e) {
            }
        }
        return null;
    }
    /** legitimate chain: result checked then return */
    public function chainCheck(array $items): mixed
    {
        foreach ($items as $x) {
            try {
                $r = $this->work($x);
                if ($r !== null) {
                    return $r;
                }
            } catch (\Throwable $e) {
            }
        }
        return null;
    }
    /** legitimate chain: work in condition */
    public function chainCond(array $items): mixed
    {
        foreach ($items as $x) {
            try {
                if (($r = $this->work($x)) !== null) {
                    return $r;
                }
            } catch (\Throwable $e) {
            }
        }
        return null;
    }
    /** guard return before work */
    public function guardReturn(array $items): void
    {
        foreach ($items as $x) {
            try {
                if ($x === 'stop') {
                    return;
                }
                $this->work($x);
            } catch (\Throwable $e) {
            }
        }
    }
    /** plain swallow in foreach: must be reported */
    public function plain(array $items): void
    {
        foreach ($items as $x) {
            try {
                $this->work($x);
            } catch (\Throwable $e) {
            }
        }
    }
    /** break on success */
    public function chainBreak(array $items): void
    {
        foreach ($items as $x) {
            try {
                $this->work($x);
                break;
            } catch (\Throwable $e) {
            }
        }
    }
    private function work(mixed $x): mixed { return $x; }
    private function after(mixed $x): void {}
}
