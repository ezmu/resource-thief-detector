<?php

namespace ResourceThief\Profiling;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

class InstrumentingAutoloader
{
    private string $cacheDir;

    public function __construct()
    {
        $this->cacheDir = storage_path('framework/rtd_cache');
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'load'], true, true);
    }

    public function load(string $class): bool
    {
        if (!str_starts_with($class, 'App\\')) {
            return false;
        }

        $file = app_path(str_replace(['App\\', '\\'], ['', '/'], $class) . '.php');

        if (!file_exists($file)) {
            return false;
        }

        $cached = $this->cacheDir . '/' . md5($file) . '.php';

        if (!file_exists($cached) || filemtime($cached) < filemtime($file)) {
            $this->instrument($file, $cached);
        }

        require_once $cached;
        return true;
    }

    private function instrument(string $source, string $dest): void
    {
        $code = file_get_contents($source);
        
        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $traverser = new NodeTraverser();
            
            $traverser->addVisitor(new class extends NodeVisitorAbstract {
                private ?Node\Stmt\Expression $exitCall = null;
                
                public function beforeTraverse(array $nodes)
                {
                    $this->exitCall = new Node\Stmt\Expression(
                        new Node\Expr\StaticCall(
                            new Node\Name\FullyQualified('ResourceThief\Profiling\Tracer'),
                            'exit',
                            [new Node\Arg(new Node\Scalar\MagicConst\Method())]
                        )
                    );
                    return null;
                }
                
                public function enterNode(Node $node)
                {
                    if ($node instanceof Node\Stmt\ClassMethod) {
                        $enterCall = new Node\Stmt\Expression(
                            new Node\Expr\StaticCall(
                                new Node\Name\FullyQualified('ResourceThief\Profiling\Tracer'),
                                'enter',
                                [new Node\Arg(new Node\Scalar\MagicConst\Method())]
                            )
                        );
                        
                        array_unshift($node->stmts, $enterCall);
                        
                        $this->addExitBeforeReturn($node->stmts);
                        
                        $hasReturn = false;
                        foreach ($node->stmts as $stmt) {
                            if ($stmt instanceof Node\Stmt\Return_) {
                                $hasReturn = true;
                                break;
                            }
                        }
                        if (!$hasReturn) {
                            $node->stmts[] = $this->exitCall;
                        }
                    }
                    return null;
                }
                
                private function addExitBeforeReturn(array &$stmts): void
                {
                    foreach ($stmts as $key => $stmt) {
                        if ($stmt instanceof Node\Stmt\Return_) {
                            array_splice($stmts, $key, 0, [$this->exitCall]);
                        }
                        if (property_exists($stmt, 'stmts') && is_array($stmt->stmts)) {
                            $this->addExitBeforeReturn($stmt->stmts);
                        }
                    }
                }
            });

            $ast = $parser->parse($code);
            $ast = $traverser->traverse($ast);
            $newCode = (new PrettyPrinter\Standard())->prettyPrintFile($ast);
            file_put_contents($dest, $newCode);
        } catch (\Exception $e) {
            copy($source, $dest);
        }
    }
}