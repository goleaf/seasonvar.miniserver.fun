<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

/**
 * @phpstan-type IntegrationCheck array{
 *     key: string,
 *     title: string,
 *     status: string,
 *     message: string,
 *     required: bool,
 *     details: list<string>
 * }
 */
final class IntegrationDoctor
{
    public const STATUS_MISSING = 'missing';

    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    private const GOOGLE_WORKSPACE_MCP_SERVERS = [
        'google-calendar',
        'google-chat',
        'google-drive',
        'google-gmail',
        'google-people',
    ];

    private const REQUIRED_PROJECT_SKILLS = [
        'laravel-best-practices',
        'tailwindcss-development',
        'seasonvar-importer',
        'seasonvar-ui',
        'seasonvar-seo',
        'seasonvar-mcp-ops',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly ExecutableFinder $executables,
    ) {}

    /**
     * @return list<IntegrationCheck>
     */
    public function checks(): array
    {
        $globalMcpServers = $this->globalMcpServers();

        return [
            $this->laravelBoostMcpCheck(),
            $this->context7McpCheck(),
            $this->playwrightMcpCheck(),
            $this->mcpExampleCheck(),
            $this->projectSkillsCheck(),
            $this->openAiDocsMcpCheck($globalMcpServers),
            $this->googleWorkspaceMcpCheck($globalMcpServers),
            $this->googleSearchConsoleCheck(),
            $this->googleAnalyticsCheck(),
            $this->googleCredentialsCheck(),
            $this->cliToolCheck('codex_cli', 'Codex CLI', 'codex', required: false),
            $this->cliToolCheck('github_cli', 'GitHub CLI', 'gh', required: false),
            $this->cliToolCheck('google_cloud_cli', 'Google Cloud CLI', 'gcloud', required: false),
            $this->cliToolCheck('pipx', 'pipx', 'pipx', required: false),
        ];
    }

    /**
     * @return array{ok: int, warning: int, missing: int}
     */
    public function summary(): array
    {
        $summary = [
            self::STATUS_OK => 0,
            self::STATUS_WARNING => 0,
            self::STATUS_MISSING => 0,
        ];

        foreach ($this->checks() as $check) {
            $summary[$check['status']]++;
        }

        return [
            'ok' => $summary[self::STATUS_OK],
            'warning' => $summary[self::STATUS_WARNING],
            'missing' => $summary[self::STATUS_MISSING],
        ];
    }

    /**
     * @param  list<string>  $details
     * @return IntegrationCheck
     */
    private function check(string $key, string $title, string $status, string $message, bool $required = false, array $details = []): array
    {
        return compact('key', 'title', 'status', 'message', 'required', 'details');
    }

    /**
     * @return list<string>
     */
    private function globalMcpServers(): array
    {
        $path = $this->homePath('.codex/config.toml');

        if ($path === null || ! $this->files->isFile($path)) {
            return [];
        }

        preg_match_all('/^\[mcp_servers\.([A-Za-z0-9_.-]+)\]/m', $this->files->get($path), $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return IntegrationCheck */
    private function laravelBoostMcpCheck(): array
    {
        $path = base_path('.codex/config.toml');

        if (! $this->files->isFile($path)) {
            return $this->check(
                'laravel_boost_mcp',
                'Laravel Boost MCP',
                self::STATUS_MISSING,
                'Файл .codex/config.toml не найден.',
                required: true,
            );
        }

        $server = $this->projectMcpServer('laravel-boost');
        $hasBoostServer = $server !== null
            && str_contains($server, 'boost:mcp')
            && str_contains($server, '--env=local')
            && str_contains($server, 'env = { APP_ENV = "local" }')
            && $this->hasAbsoluteWorkingDirectory($server);

        return $this->check(
            'laravel_boost_mcp',
            'Laravel Boost MCP',
            $hasBoostServer ? self::STATUS_OK : self::STATUS_MISSING,
            $hasBoostServer
                ? 'Проектный MCP Laravel Boost настроен с наследуемым APP_ENV=local.'
                : 'Laravel Boost MCP должен использовать абсолютный cwd, --env=local и наследуемый APP_ENV=local.',
            required: true,
        );
    }

    /** @return IntegrationCheck */
    private function context7McpCheck(): array
    {
        $server = $this->projectMcpServer('context7');
        $configured = $server !== null
            && str_contains($server, '@upstash/context7-mcp')
            && str_contains($server, 'required = false')
            && ! str_contains($server, '--api-key')
            && ! str_contains($server, 'CONTEXT7_API_KEY');

        return $this->check(
            'context7_mcp',
            'Context7 MCP',
            $configured ? self::STATUS_OK : self::STATUS_MISSING,
            $configured
                ? 'Context7 настроен как необязательный project MCP без сохраненного API key.'
                : 'Context7 MCP отсутствует или содержит небезопасную project-конфигурацию.',
            required: true,
        );
    }

    /** @return IntegrationCheck */
    private function playwrightMcpCheck(): array
    {
        $server = $this->projectMcpServer('playwright');
        $configured = $server !== null
            && str_contains($server, '@playwright/mcp@latest')
            && str_contains($server, '--headless')
            && str_contains($server, '--isolated')
            && str_contains($server, 'output/playwright')
            && $this->hasAbsoluteWorkingDirectory($server)
            && str_contains($server, 'required = false');

        return $this->check(
            'playwright_mcp',
            'Playwright MCP',
            $configured ? self::STATUS_OK : self::STATUS_MISSING,
            $configured
                ? 'Playwright настроен как необязательный headless isolated project MCP.'
                : 'Playwright MCP должен использовать абсолютный cwd, headless isolated режим и ignored output.',
            required: true,
        );
    }

    private function projectMcpServer(string $name): ?string
    {
        $path = base_path('.codex/config.toml');

        if (! $this->files->isFile($path)) {
            return null;
        }

        $pattern = '/^\[mcp_servers\.'.preg_quote($name, '/').'\]\R(?<server>.*?)(?=^\[|\z)/ms';

        if (preg_match($pattern, $this->files->get($path), $matches) !== 1) {
            return null;
        }

        return $matches['server'];
    }

    private function hasAbsoluteWorkingDirectory(string $server): bool
    {
        if (preg_match('/^cwd\s*=\s*"(?<cwd>[^"\r\n]+)"$/m', $server, $matches) !== 1) {
            return false;
        }

        $workingDirectory = $matches['cwd'];
        $isAbsolute = str_starts_with($workingDirectory, '/')
            || str_starts_with($workingDirectory, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $workingDirectory) === 1;

        return $isAbsolute
            && ! str_contains($workingDirectory, "\0")
            && preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $workingDirectory) !== 1;
    }

    /** @return IntegrationCheck */
    private function mcpExampleCheck(): array
    {
        $exists = $this->files->isFile(base_path('.codex/mcp.example.toml'));

        return $this->check(
            'mcp_example',
            'MCP example config',
            $exists ? self::STATUS_OK : self::STATUS_WARNING,
            $exists
                ? 'Шаблон .codex/mcp.example.toml есть в проекте.'
                : 'Шаблон .codex/mcp.example.toml отсутствует.',
        );
    }

    /** @return IntegrationCheck */
    private function projectSkillsCheck(): array
    {
        $path = base_path('boost.json');

        if (! $this->files->isFile($path)) {
            return $this->check(
                'project_skills',
                'Project skills',
                self::STATUS_MISSING,
                'boost.json не найден.',
                required: true,
            );
        }

        $boost = json_decode($this->files->get($path), true);
        $skills = is_array($boost) && isset($boost['skills']) && is_array($boost['skills'])
            ? $boost['skills']
            : [];
        $missing = array_values(array_diff(self::REQUIRED_PROJECT_SKILLS, $skills));

        return $this->check(
            'project_skills',
            'Project skills',
            $missing === [] ? self::STATUS_OK : self::STATUS_WARNING,
            $missing === []
                ? 'Все проектные skills перечислены в boost.json.'
                : 'В boost.json не хватает skills: '.implode(', ', $missing).'.',
            required: true,
            details: $skills,
        );
    }

    /**
     * @param  list<string>  $globalMcpServers
     * @return IntegrationCheck
     */
    private function openAiDocsMcpCheck(array $globalMcpServers): array
    {
        $configured = in_array('openaiDeveloperDocs', $globalMcpServers, true);

        return $this->check(
            'openai_docs_mcp',
            'OpenAI docs MCP',
            $configured ? self::STATUS_OK : self::STATUS_WARNING,
            $configured
                ? 'openaiDeveloperDocs зарегистрирован в user-level Codex MCP config.'
                : 'openaiDeveloperDocs не найден в user-level Codex MCP config.',
        );
    }

    /**
     * @param  list<string>  $globalMcpServers
     * @return IntegrationCheck
     */
    private function googleWorkspaceMcpCheck(array $globalMcpServers): array
    {
        $missing = array_values(array_diff(self::GOOGLE_WORKSPACE_MCP_SERVERS, $globalMcpServers));

        return $this->check(
            'google_workspace_mcp',
            'Google Workspace MCP',
            $missing === [] ? self::STATUS_WARNING : self::STATUS_MISSING,
            $missing === []
                ? 'Google Workspace MCP endpoints зарегистрированы; проверьте OAuth через codex mcp list/login.'
                : 'Не зарегистрированы Google Workspace MCP endpoints: '.implode(', ', $missing).'.',
            details: array_values(array_intersect(self::GOOGLE_WORKSPACE_MCP_SERVERS, $globalMcpServers)),
        );
    }

    /** @return IntegrationCheck */
    private function googleSearchConsoleCheck(): array
    {
        $config = config('services.google.search_console', []);
        $enabled = (bool) ($config['enabled'] ?? false);
        $siteUrl = (string) ($config['site_url'] ?? '');
        $readonly = (bool) ($config['readonly'] ?? true);

        if (! $enabled) {
            return $this->check(
                'google_search_console',
                'Google Search Console',
                self::STATUS_WARNING,
                'Search Console выключен в конфигурации; это безопасное значение по умолчанию.',
            );
        }

        if ($siteUrl === '') {
            return $this->check(
                'google_search_console',
                'Google Search Console',
                self::STATUS_MISSING,
                'Search Console включен, но site URL не задан.',
            );
        }

        return $this->check(
            'google_search_console',
            'Google Search Console',
            $readonly ? self::STATUS_OK : self::STATUS_WARNING,
            $readonly
                ? 'Search Console включен в read-only режиме.'
                : 'Search Console включен не в read-only режиме; проверьте необходимость write scope.',
            details: ['site_url задан'],
        );
    }

    /** @return IntegrationCheck */
    private function googleAnalyticsCheck(): array
    {
        $config = config('services.google.analytics', []);
        $enabled = (bool) ($config['enabled'] ?? false);
        $propertyId = (string) ($config['property_id'] ?? '');

        if (! $enabled) {
            return $this->check(
                'google_analytics',
                'Google Analytics 4',
                self::STATUS_WARNING,
                'GA4 выключен в конфигурации; это безопасное значение по умолчанию.',
            );
        }

        return $this->check(
            'google_analytics',
            'Google Analytics 4',
            $propertyId !== '' ? self::STATUS_OK : self::STATUS_MISSING,
            $propertyId !== ''
                ? 'GA4 включен, property ID задан.'
                : 'GA4 включен, но property ID не задан.',
        );
    }

    /** @return IntegrationCheck */
    private function googleCredentialsCheck(): array
    {
        $credentials = (string) config('services.google.application_credentials', '');

        if ($credentials === '') {
            return $this->check(
                'google_credentials',
                'Google credentials',
                self::STATUS_WARNING,
                'GOOGLE_APPLICATION_CREDENTIALS не задан; Google API клиенты останутся выключенными.',
            );
        }

        return $this->check(
            'google_credentials',
            'Google credentials',
            $this->files->isFile($credentials) ? self::STATUS_OK : self::STATUS_MISSING,
            $this->files->isFile($credentials)
                ? 'GOOGLE_APPLICATION_CREDENTIALS задан и файл доступен.'
                : 'GOOGLE_APPLICATION_CREDENTIALS задан, но файл недоступен.',
        );
    }

    /** @return IntegrationCheck */
    private function cliToolCheck(string $key, string $title, string $executable, bool $required): array
    {
        $path = $this->executables->find($executable);

        return $this->check(
            $key,
            $title,
            $path !== null ? self::STATUS_OK : ($required ? self::STATUS_MISSING : self::STATUS_WARNING),
            $path !== null
                ? $executable.' найден в PATH.'
                : $executable.' не найден в PATH.',
            required: $required,
        );
    }

    private function homePath(string $suffix): ?string
    {
        $serverHome = $_SERVER['HOME'] ?? null;
        $home = is_string($serverHome) && $serverHome !== '' ? $serverHome : getenv('HOME');

        if (! is_string($home) || $home === '') {
            return null;
        }

        return rtrim($home, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($suffix, DIRECTORY_SEPARATOR);
    }
}
