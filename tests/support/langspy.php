<?php
// Stands in for the lang resource in LangBatchTest: it records the jobs that would go to the
// background instead of reaching a daemon or a process, and it opens the collecting methods so a
// test can drive them directly. Loaded after the build, because it extends the compiled class.
final class langspy extends lang
{
    /** @var array<int, array{from: string, to: string, json: string}> */
    public array $jobs = [];
    public int $tries = 0;
    public bool $refuse = false;
    public bool $throwOnDispatch = false;
    /** @var null|callable */
    public $onDispatch = null;

    protected function dispatch($from, $to, $json): bool
    {
        $this->tries++;
        if ($this->throwOnDispatch) {
            throw new RuntimeException('dispatch failed');
        }
        $job = ['from' => (string) $from, 'to' => (string) $to, 'json' => (string) $json];
        $this->jobs[] = $job;
        if (is_callable($this->onDispatch)) {
            return (bool) ($this->onDispatch)($job);
        }
        return !$this->refuse;
    }

    /** Collect phrases the way translation() does. */
    public function take($from, $to, array $missing): void
    {
        $this->collect($from, $to, $missing);
    }

    /** How many phrases are still waiting under this key. */
    public function waiting(string $key): int
    {
        return count($this->pending[$key] ?? []);
    }

    public function hashOf($from, $text): string
    {
        return $this->hash($from, $text);
    }

    public function translation($from, $text, ...$args): string
    {
        return parent::translation($from, $text, ...$args);
    }
}
