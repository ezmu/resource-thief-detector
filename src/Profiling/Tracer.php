<?php

namespace ResourceThief\Profiling;

class Tracer
{
    private static array $stack = [];
    private static array $tree = [];
    private static bool $enabled = false;
    private static int $maxDepth = 5;

    public static function enable(int $depth = 5): void
    {
        self::$enabled = true;
        self::$maxDepth = $depth;
        self::$tree = [];
        self::$stack = [];
    }

    public static function enter(string $method): void
    {
        if (!self::$enabled) return;
        if (count(self::$stack) >= self::$maxDepth) return;

        $id = count(self::$tree);
        $frame = [
            'id' => $id,
            'parent_id' => empty(self::$stack) ? null : end(self::$stack)['id'],
            'method' => $method,
            'start_time' => hrtime(true),
            'start_memory' => memory_get_usage(),
            'depth' => count(self::$stack),
        ];

        self::$stack[] = $frame;
        self::$tree[$id] = $frame;
    }

    public static function exit(string $method): void
    {
        if (!self::$enabled || empty(self::$stack)) return;

        $frame = array_pop(self::$stack);

        if (isset(self::$tree[$frame['id']])) {
            self::$tree[$frame['id']]['duration_ms'] = round((hrtime(true) - $frame['start_time']) / 1_000_000, 4);
            self::$tree[$frame['id']]['memory_kb'] = round((memory_get_usage() - $frame['start_memory']) / 1024, 2);
        }
    }

    public static function getTree(): array 
    { 
        return self::$tree; 
    }
    
    public static function disable(): void 
    { 
        self::$enabled = false; 
    }
}