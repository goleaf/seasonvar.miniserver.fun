<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReadmePolicyScriptTest extends TestCase
{
    private string $readmePath;

    private ?string $repositoryPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->readmePath = sys_get_temp_dir().'/seasonvar-readme-policy-'.bin2hex(random_bytes(6)).'.md';
    }

    protected function tearDown(): void
    {
        File::delete($this->readmePath);

        if ($this->repositoryPath !== null) {
            File::deleteDirectory($this->repositoryPath);
        }

        parent::tearDown();
    }

    public function test_it_accepts_russian_prose_and_technical_identifiers(): void
    {
        File::put($this->readmePath, <<<'MARKDOWN'
# Каталог Seasonvar

Проект работает на Laravel 13 и использует маршрут `/titles`.

```bash
php artisan test
```

## Дорожная карта

- Запланировано улучшение поиска.

## История обновлений для посетителей

### 16 июля 2026 года

- Добавлен новый каталог.
MARKDOWN);

        $process = $this->runPolicyCheck();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    #[DataProvider('invalidReadmes')]
    public function test_it_rejects_invalid_readme(string $contents, string $message): void
    {
        File::put($this->readmePath, $contents);

        $process = $this->runPolicyCheck();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString($message, $process->getErrorOutput());
    }

    public function test_staged_product_changes_require_a_readme_update(): void
    {
        $this->repositoryPath = sys_get_temp_dir().'/seasonvar-readme-repository-'.bin2hex(random_bytes(6));

        File::makeDirectory($this->repositoryPath.'/resources/js', recursive: true);
        File::put($this->repositoryPath.'/README.md', $this->validReadme());
        File::put($this->repositoryPath.'/resources/js/app.js', "export default 'исходное состояние';\n");

        $this->runGit('init', '-b', 'main');
        $this->runGit('config', 'user.name', 'Seasonvar Test');
        $this->runGit('config', 'user.email', 'seasonvar@example.com');
        $this->runGit('add', '.');
        $this->runGit('commit', '-m', 'Исходное состояние');

        File::put($this->repositoryPath.'/resources/js/app.js', "export default 'новое состояние';\n");
        $this->runGit('add', 'resources/js/app.js');

        $process = new Process(['bash', base_path('scripts/check-readme-policy.sh'), '--staged']);
        $process->setWorkingDirectory($this->repositoryPath);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'изменения продукта должны включать обновлённый README.md',
            $process->getErrorOutput(),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidReadmes(): array
    {
        return [
            'нет дорожной карты' => [
                <<<'MARKDOWN'
# Каталог Seasonvar

## История обновлений для посетителей

- Добавлен новый каталог.
MARKDOWN,
                'Отсутствует раздел «Дорожная карта»',
            ],
            'после истории есть раздел' => [
                <<<'MARKDOWN'
# Каталог Seasonvar

## Дорожная карта

- Запланировано улучшение поиска.

## История обновлений для посетителей

- Добавлен новый каталог.

## Дополнительный раздел

- Этот раздел расположен слишком поздно.
MARKDOWN,
                'История обновлений для посетителей должна быть последним разделом',
            ],
            'английская строка' => [
                <<<'MARKDOWN'
# Каталог Seasonvar

English only sentence.

## Дорожная карта

- Запланировано улучшение поиска.

## История обновлений для посетителей

- Добавлен новый каталог.
MARKDOWN,
                'Строка 3 должна содержать русский текст',
            ],
        ];
    }

    private function runPolicyCheck(): Process
    {
        $process = new Process([
            'bash',
            base_path('scripts/check-readme-policy.sh'),
            $this->readmePath,
        ]);
        $process->run();

        return $process;
    }

    private function runGit(string ...$arguments): void
    {
        $process = new Process(['git', ...$arguments]);
        $process->setWorkingDirectory($this->repositoryPath);
        $process->mustRun();
    }

    private function validReadme(): string
    {
        return <<<'MARKDOWN'
# Каталог Seasonvar

## Дорожная карта

- Запланировано улучшение поиска.

## История обновлений для посетителей

- Добавлен новый каталог.
MARKDOWN;
    }
}
