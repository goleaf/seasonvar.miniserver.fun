<?php

declare(strict_types=1);

namespace Tests\Unit;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class EagerLoadProjectionContractTest extends TestCase
{
    /** @var list<string> */
    private const ELOQUENT_LOAD_METHODS = ['with', 'load', 'loadMissing'];

    /** @var list<string> */
    private const FULL_AGGREGATE_EXCEPTIONS = [
        'app/Services/Seasonvar/SeasonvarTitleMerger.php',
    ];

    public function test_literal_eager_loads_have_explicit_related_column_projections(): void
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $violations = [];
        $root = dirname(__DIR__, 2);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($root.'/', '', $file->getPathname());
            $nodes = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
            $calls = $finder->find($nodes, fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), self::ELOQUENT_LOAD_METHODS, true));

            foreach ($calls as $call) {
                if ($relativePath === 'app/Http/Controllers/Auth/VerifyEmailController.php'
                    || $call->args === []) {
                    continue;
                }

                $this->inspectNode(
                    $call->args[0]->value,
                    $relativePath,
                    $call->getStartLine(),
                    $finder,
                    $violations,
                );
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /** @param list<string> $violations */
    private function inspectNode(
        Node $node,
        string $relativePath,
        int $line,
        NodeFinder $finder,
        array &$violations,
    ): void {
        if ($node instanceof Node\Scalar\String_) {
            if (! str_contains($node->value, ':') && ! in_array($relativePath, self::FULL_AGGREGATE_EXCEPTIONS, true)) {
                $violations[] = $relativePath.':'.$line.' loads '.$node->value.' without a column projection.';
            }

            return;
        }

        if ($node instanceof Node\Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }

                if ($item->key instanceof Node\Scalar\String_
                    && ($item->value instanceof Node\Expr\Closure || $item->value instanceof Node\Expr\ArrowFunction)) {
                    $hasProjection = $finder->findFirst($item->value, fn (Node $candidate): bool => $candidate instanceof Node\Expr\MethodCall
                        && $candidate->name instanceof Node\Identifier
                        && in_array($candidate->name->toString(), ['select', 'addSelect'], true));

                    if ($hasProjection === null) {
                        $violations[] = $relativePath.':'.$item->getStartLine().' loads '.$item->key->value.' without a column projection.';
                    }

                    continue;
                }

                $this->inspectNode($item->value, $relativePath, $item->getStartLine(), $finder, $violations);
            }

            return;
        }

        if ($node instanceof Node\Expr\FuncCall) {
            foreach ($node->args as $argument) {
                $this->inspectNode($argument->value, $relativePath, $argument->getStartLine(), $finder, $violations);
            }
        }
    }
}
