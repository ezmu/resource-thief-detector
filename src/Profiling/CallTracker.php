<?php

namespace ResourceThief\Profiling;

class CallTracker
{
    private array $calls = [];
    private array $stack = [];
    private int $depth = 0;
    private int $callId = 0;
    private bool $active = false;

    public function start(): void
    {
        $this->calls = [];
        $this->stack = [];
        $this->depth = 0;
        $this->callId = 0;
        $this->active = true;
        
        // Start tracking
        $this->track();
    }

    public function track(): void
    {
        if (!$this->active) return;
        
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        
        // Find all user function calls
        for ($i = 0; $i < count($backtrace); $i++) {
            $frame = $backtrace[$i];
            $function = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';
            $fullName = $class ? $class . '::' . $function : $function;
            
            $skip = ['{closure}', 'call_user_func', 'call_user_func_array', 'register_tick_function', 'track', 'start'];
            $skipClasses = ['CallTracker', 'TraceCommand', 'Illuminate', 'Symfony', 'Composer'];
            
            $shouldSkip = false;
            foreach ($skipClasses as $skipClass) {
                if (strpos($class, $skipClass) !== false) {
                    $shouldSkip = true;
                    break;
                }
            }
            
            if (in_array($function, $skip) || $shouldSkip) {
                continue;
            }
            
            $callKey = $fullName . $frame['line'] . $i;
            $callId = md5($callKey);
            
            if (!isset($this->calls[$callId])) {
                $this->calls[$callId] = [
                    'function' => $fullName,
                    'file' => $frame['file'] ?? 'unknown',
                    'line' => $frame['line'] ?? 0,
                    'start_time' => microtime(true),
                    'start_memory' => memory_get_usage(),
                    'depth' => $this->depth,
                    'parent_id' => end($this->stack) ?: null,
                ];
                $this->stack[] = $callId;
                $this->depth++;
            } else {
                if (!isset($this->calls[$callId]['end_time'])) {
                    $this->calls[$callId]['end_time'] = microtime(true);
                    $this->calls[$callId]['end_memory'] = memory_get_usage();
                    $this->calls[$callId]['duration'] = round(($this->calls[$callId]['end_time'] - $this->calls[$callId]['start_time']) * 1000, 2);
                    $this->calls[$callId]['memory'] = round(($this->calls[$callId]['end_memory'] - $this->calls[$callId]['start_memory']) / 1024, 2);
                    array_pop($this->stack);
                    $this->depth--;
                }
            }
        }
    }

    public function stop(): array
    {
        $this->active = false;
        $result = [];
        foreach ($this->calls as $call) {
            if (isset($call['duration'])) {
                $result[] = [
                    'function' => $call['function'],
                    'duration_ms' => $call['duration'],
                    'memory_kb' => $call['memory'],
                    'depth' => $call['depth'],
                    'parent_id' => $call['parent_id'],
                    'file' => $call['file'],
                    'line' => $call['line'],
                ];
            }
        }
        return $result;
    }
}
