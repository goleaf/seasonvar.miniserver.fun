<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class CollectionEachCallbackSafetyTest extends TestCase
{
    public function test_each_callbacks_never_use_implicit_arrow_function_return_values(): void
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $violations = [];

        foreach ([base_path('app'), base_path('tests')] as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                $nodes = is_string($contents) ? $parser->parse($contents) : null;

                $calls = $finder->find(
                    $nodes ?? [],
                    fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                        || $node instanceof Node\Expr\NullsafeMethodCall,
                );

                foreach ($calls as $call) {
                    if (! $call->name instanceof Node\Identifier
                        || ! in_array($call->name->toString(), ['each', 'eachSpread'], true)
                        || ! ($call->args[0]->value ?? null) instanceof Node\Expr\ArrowFunction) {
                        continue;
                    }

                    $violations[] = str_replace(base_path().'/', '', $file->getPathname()).':'.$call->getStartLine();
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Arrow callbacks can return strict false and stop Collection::each() early:\n".implode("\n", $violations),
        );
    }
}
