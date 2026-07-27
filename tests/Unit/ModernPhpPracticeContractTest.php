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

final class ModernPhpPracticeContractTest extends TestCase
{
    public function test_application_code_uses_explicit_error_and_dependency_boundaries(): void
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $violations = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $nodes = is_string($contents) ? $parser->parse($contents) : null;

            foreach ($finder->find($nodes ?? [], $this->isForbiddenPractice(...)) as $node) {
                $violations[] = sprintf(
                    '%s:%d [%s]',
                    str_replace(base_path().'/', '', $file->getPathname()),
                    $node->getStartLine(),
                    $this->practiceName($node),
                );
            }
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            "Application code must use explicit exceptions, Composer autoloading and bound SQL:\n"
                .implode("\n", $violations),
        );
    }

    private function isForbiddenPractice(Node $node): bool
    {
        if ($node instanceof Node\Expr\ErrorSuppress
            || $node instanceof Node\Expr\Exit_
            || $node instanceof Node\Expr\Include_) {
            return true;
        }

        return $node instanceof Node\Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'unprepared';
    }

    private function practiceName(Node $node): string
    {
        return match (true) {
            $node instanceof Node\Expr\ErrorSuppress => 'error suppression',
            $node instanceof Node\Expr\Exit_ => 'exit/die',
            $node instanceof Node\Expr\Include_ => 'runtime include/require',
            default => 'unprepared SQL',
        };
    }
}
