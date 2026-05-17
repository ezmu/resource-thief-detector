<?php

namespace ResourceThief\Profiling;

use Illuminate\Support\Facades\DB;

class ProfileManager
{
    private string $profileId;
    private array $baseline = [];
    private array $improvements = [];
    private array $history = [];

    public function __construct(string $name)
    {
        $this->profileId = date('Y-m-d_H-i-s') . '_' . preg_replace('/[^a-z0-9]/i', '_', $name);
    }

    public static function create(string $name): self
    {
        return new self($name);
    }

    public function baseline(callable $code): array
    {
        $this->baseline = $this->measure('baseline', $code);
        $this->history[] = ['type' => 'baseline', 'timestamp' => microtime(true), 'data' => $this->baseline];
        return $this->baseline;
    }

    public function afterFix(string $fixName, callable $code): array
    {
        $measurement = $this->measure($fixName, $code);
        $this->improvements[] = [
            'name' => $fixName,
            'timestamp' => microtime(true),
            'data' => $measurement,
            'diff' => $this->calculateDiff($this->baseline, $measurement)
        ];
        $this->history[] = ['type' => 'fix', 'name' => $fixName, 'timestamp' => microtime(true), 'data' => $measurement];
        return $measurement;
    }

    public function push(): void
    {
        $this->history[] = ['type' => 'checkpoint', 'timestamp' => microtime(true)];
    }

    public function pop(): array
    {
        $lastCheckpoint = null;
        $toRemove = [];
        
        for ($i = count($this->history) - 1; $i >= 0; $i--) {
            if ($this->history[$i]['type'] === 'checkpoint') {
                $lastCheckpoint = $i;
                break;
            }
            $toRemove[] = $i;
        }
        
        foreach ($toRemove as $index) {
            unset($this->history[$index]);
        }
        
        $this->history = array_values($this->history);
        
        if (!empty($this->history) && $this->history[count($this->history)-1]['type'] === 'fix') {
            $last = $this->history[count($this->history)-1];
            $this->improvements = array_filter($this->improvements, fn($imp) => $imp['timestamp'] !== $last['timestamp']);
        }
        
        return $this->getCurrentState();
    }

    public function compare(): array
    {
        $comparison = [];
        
        foreach ($this->improvements as $improvement) {
            $diff = $improvement['diff'];
            $comparison[] = [
                'fix' => $improvement['name'],
                'time_change_ms' => $diff['time_ms'],
                'time_percent' => $diff['time_percent'],
                'memory_change_kb' => $diff['memory_kb'],
                'memory_percent' => $diff['memory_percent'],
                'query_change' => $diff['queries'],
                'status' => $this->getStatus($diff),
            ];
        }
        
        return $comparison;
    }

    public function generateReport(): string
    {
        $report = [];
        $report[] = str_repeat('=', 80);
        $report[] = "PROFILE REPORT: {$this->profileId}";
        $report[] = str_repeat('=', 80);
        $report[] = "";
        
        $report[] = "BASELINE MEASUREMENT";
        $report[] = str_repeat('-', 40);
        $report[] = "  Time:    {$this->baseline['time_ms']} ms";
        $report[] = "  Memory:  {$this->baseline['memory_kb']} KB";
        $report[] = "  Queries: {$this->baseline['queries']}";
        $report[] = "";
        
        $report[] = "IMPROVEMENTS";
        $report[] = str_repeat('-', 40);
        
        foreach ($this->improvements as $i => $imp) {
            $diff = $imp['diff'];
            $report[] = sprintf("  %d. %s", $i + 1, $imp['name']);
            $report[] = sprintf("     Time:   %+.2f ms (%+.1f%%)", $diff['time_ms'], $diff['time_percent']);
            $report[] = sprintf("     Memory: %+.2f KB (%+.1f%%)", $diff['memory_kb'], $diff['memory_percent']);
            $report[] = sprintf("     Queries: %+d", $diff['queries']);
            $report[] = "";
        }
        
        $final = $this->getFinalImprovement();
        if ($final) {
            $report[] = "FINAL RESULT";
            $report[] = str_repeat('-', 40);
            $report[] = sprintf("  Time improvement:   %.1f%% (%s ms)", 
                abs($final['time_percent']), 
                $final['time_ms'] < 0 ? '-' . abs($final['time_ms']) : '+' . $final['time_ms']);
            $report[] = sprintf("  Memory improvement: %.1f%% (%s KB)", 
                abs($final['memory_percent']),
                $final['memory_kb'] < 0 ? '-' . abs($final['memory_kb']) : '+' . $final['memory_kb']);
            $report[] = sprintf("  Query reduction:    %d", abs($final['queries']));
        }
        
        $report[] = "";
        $report[] = str_repeat('=', 80);
        
        return implode("\n", $report);
    }

    public function save(): string
    {
        $filename = storage_path("profiles/{$this->profileId}.json");
        
        if (!is_dir(dirname($filename))) {
            mkdir(dirname($filename), 0755, true);
        }
        
        file_put_contents($filename, json_encode([
            'profile_id' => $this->profileId,
            'created_at' => date('Y-m-d H:i:s'),
            'baseline' => $this->baseline,
            'improvements' => $this->improvements,
            'history' => $this->history,
            'final_comparison' => $this->compare(),
            'final_improvement' => $this->getFinalImprovement(),
        ], JSON_PRETTY_PRINT));
        
        return $filename;
    }

    public static function load(string $profileId): ?array
    {
        $filename = storage_path("profiles/{$profileId}.json");
        
        if (!file_exists($filename)) {
            return null;
        }
        
        return json_decode(file_get_contents($filename), true);
    }

    private function measure(string $name, callable $code): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        DB::enableQueryLog();
        
        $result = $code();
        
        $queries = DB::getQueryLog();
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        DB::disableQueryLog();
        
        return [
            'name' => $name,
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'memory_mb' => round(($endMemory - $startMemory) / 1024 / 1024, 2),
            'queries' => count($queries),
            'query_time_ms' => round(array_sum(array_column($queries, 'time')), 2),
            'result' => $result,
        ];
    }

    private function calculateDiff(array $baseline, array $current): array
    {
        return [
            'time_ms' => round($current['time_ms'] - $baseline['time_ms'], 2),
            'time_percent' => round(($current['time_ms'] - $baseline['time_ms']) / $baseline['time_ms'] * 100, 1),
            'memory_kb' => round($current['memory_kb'] - $baseline['memory_kb'], 2),
            'memory_percent' => round(($current['memory_kb'] - $baseline['memory_kb']) / $baseline['memory_kb'] * 100, 1),
            'queries' => $current['queries'] - $baseline['queries'],
        ];
    }

    private function getStatus(array $diff): string
    {
        if ($diff['time_ms'] < -20 && $diff['memory_kb'] < -1024) {
            return 'EXCELLENT';
        }
        if ($diff['time_ms'] < -10 || $diff['memory_kb'] < -512) {
            return 'GOOD';
        }
        if ($diff['time_ms'] < 0 || $diff['memory_kb'] < 0) {
            return 'MINOR';
        }
        if ($diff['time_ms'] > 50 || $diff['memory_kb'] > 5120) {
            return 'REGRESSION';
        }
        return 'NEUTRAL';
    }

    private function getFinalImprovement(): ?array
    {
        if (empty($this->improvements)) {
            return null;
        }
        
        $last = $this->improvements[count($this->improvements) - 1];
        return $last['diff'];
    }

    private function getCurrentState(): array
    {
        if (empty($this->history)) {
            return ['empty' => true];
        }
        
        $last = $this->history[count($this->history) - 1];
        return $last['data'] ?? ['no_data' => true];
    }
}
