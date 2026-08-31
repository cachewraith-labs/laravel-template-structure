<?php

declare(strict_types=1);

namespace Cachewraith\LaravelTemplateStructure\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Cachewraith\LaravelTemplateStructure\LaravelTemplateStructureServiceProvider as PackageProvider;

/**
 * Scaffolds the template structure into the host application.
 *
 * Template Method: handle() fixes the order of the installation — publish,
 * copy, wire, report — while each step is a small private method that knows
 * one file format. Adding a step means adding a method and one line to
 * handle(), not restructuring the command.
 *
 * Every step is idempotent and every step fails soft: when a file cannot be
 * patched automatically (because the application has already customised it),
 * the command records the exact snippet the developer should paste and carries
 * on, rather than aborting halfway through a half-scaffolded application.
 */
final class InstallCommand extends Command
{
    protected $signature = 'cachewraith:install
                            {--force : Overwrite scaffolded files that already exist}';

    protected $description = 'Scaffold the clean, OOAD-based, OWASP-hardened versioned-API structure into this application';

    /** @var array<int, string> */
    private array $created = [];

    /** @var array<int, string> */
    private array $skipped = [];

    /** @var array<int, string> */
    private array $wired = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $manual = [];

    public function handle(Filesystem $files): int
    {
        $this->components->info('Installing cachewraith/laravel-template-structure...');

        $this->publishStubs();

        $source = $this->stubSource($files);
        $this->line('  <fg=gray>Stub source: '.$this->relative($source).'</>');

        $this->copyTree($files, $source.'/app', app_path());
        $this->copyTree($files, $source.'/routes/api', base_path('routes/api'));
        $this->copyTree($files, $source.'/config', config_path());
        $this->applyDataObjectVariant($files, $source);

        if ($this->isLaravel11OrAbove()) {
            $this->wireBootstrapApp($files);
        } else {
            $this->wireRouteServiceProvider($files);
            $this->wireHttpKernel($files);
            $this->wireLegacyExceptionHandler($files);
        }

        $this->registerProviders($files);
        $this->appendRepositoryBindings($files);
        $this->noteEnvExample($files);

        $this->summary();

        return self::SUCCESS;
    }

    /*
    |----------------------------------------------------------------------
    | Steps
    |----------------------------------------------------------------------
    */

    private function publishStubs(): void
    {
        $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'cachewraith-template-stubs',
            '--force' => $this->option('force') ? true : null,
        ]));
    }

    /**
     * Prefer stubs the team has published (and possibly customised) over the
     * packaged originals, so re-running the installer preserves local edits.
     */
    private function stubSource(Filesystem $files): string
    {
        $published = base_path('stubs/cachewraith-template');

        return $files->isDirectory($published.'/app')
            ? $published
            : PackageProvider::packagePath('stubs');
    }

    /**
     * Copy a directory tree, never clobbering existing application files
     * unless --force was given.
     */
    private function copyTree(Filesystem $files, string $from, string $to): void
    {
        if (! $files->isDirectory($from)) {
            return;
        }

        $files->ensureDirectoryExists($to);

        foreach ($files->allFiles($from, true) as $file) {
            $relative = $file->getRelativePathname();
            $target = $to.DIRECTORY_SEPARATOR.$relative;

            if ($files->exists($target) && ! $this->option('force')) {
                $this->skipped[] = $this->relative($target);

                continue;
            }

            $files->ensureDirectoryExists(dirname($target));
            $files->copy($file->getPathname(), $target);
            $this->created[] = $this->relative($target);
        }
    }

    /**
     * Swap the plain readonly DTO for the spatie/laravel-data flavour when the
     * host application actually has that package.
     */
    private function applyDataObjectVariant(Filesystem $files, string $source): void
    {
        if (! class_exists(\Spatie\LaravelData\Data::class)) {
            return;
        }

        $variant = $source.'/variants/UserData.spatie.php';
        $target = app_path('DTOs/UserData.php');

        if (! $files->exists($variant)) {
            return;
        }

        // Only replace the file we generated ourselves.
        if ($files->exists($target)
            && ! in_array($this->relative($target), $this->created, true)
            && ! $this->option('force')) {
            return;
        }

        $files->copy($variant, $target);
        $this->wired[] = 'App\DTOs\UserData now extends Spatie\LaravelData\Data';
    }

    /*
    |----------------------------------------------------------------------
    | Laravel 11 / 12 wiring — bootstrap/app.php
    |----------------------------------------------------------------------
    */

    private function wireBootstrapApp(Filesystem $files): void
    {
        $path = base_path('bootstrap/app.php');

        if (! $files->exists($path)) {
            $this->manual[] = ['bootstrap/app.php', $this->routingSnippet()];

            return;
        }

        $contents = $files->get($path);
        $contents = $this->injectRouting($contents);
        $contents = $this->injectMiddleware($contents);
        $contents = $this->injectExceptions($contents);

        $files->put($path, $contents);
    }

    private function injectRouting(string $contents): string
    {
        if (str_contains($contents, "routes/api/{\$version}.php") || str_contains($contents, 'routes/api/v1.php')) {
            $this->wired[] = 'bootstrap/app.php already loads routes/api/*.php';

            return $contents;
        }

        $open = strpos($contents, '->withRouting(');

        if ($open === false) {
            $this->manual[] = ['bootstrap/app.php (inside ->withRouting())', $this->routingSnippet()];

            return $contents;
        }

        $parenAt = $open + strlen('->withRouting');
        $close = $this->matchingParen($contents, $parenAt);

        if ($close === null) {
            $this->manual[] = ['bootstrap/app.php (inside ->withRouting())', $this->routingSnippet()];

            return $contents;
        }

        // An existing then: closure is the application's own; merging into it
        // automatically would be guesswork, so hand it back to the developer.
        if (str_contains(substr($contents, $open, $close - $open), 'then:')) {
            $this->manual[] = ['bootstrap/app.php (inside the existing then: closure)', $this->routingGroupSnippet()];

            return $contents;
        }

        $before = rtrim(substr($contents, 0, $close));

        // ->withRouting() may already end with a comma, or have no arguments
        // at all; both must stay syntactically valid after the injection.
        if (! str_ends_with($before, ',') && ! str_ends_with($before, '(')) {
            $before .= ',';
        }

        $injected = $before."\n".$this->routingSnippet()."\n    ".substr($contents, $close);

        $this->wired[] = 'bootstrap/app.php: routes/api/{version}.php loaded under the api middleware group';

        return $injected;
    }

    private function injectMiddleware(string $contents): string
    {
        if (str_contains($contents, 'Security\\SecurityHeaders')) {
            $this->wired[] = 'bootstrap/app.php already registers the security middleware';

            return $contents;
        }

        $pattern = '/->withMiddleware\(\s*function\s*\(\s*[\\\\\w]*Middleware\s+\$middleware\s*\)\s*(?::\s*void\s*)?\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['bootstrap/app.php (inside ->withMiddleware())', $this->middlewareSnippet()];

            return $contents;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);

        $this->wired[] = 'bootstrap/app.php: ForceJsonResponse prepended to the api group, SecurityHeaders appended globally';

        return substr($contents, 0, $at)."\n".$this->middlewareSnippet()."\n".substr($contents, $at);
    }

    private function injectExceptions(string $contents): string
    {
        if (str_contains($contents, 'ApiExceptionHandler')) {
            $this->wired[] = 'bootstrap/app.php already registers ApiExceptionHandler';

            return $contents;
        }

        $pattern = '/->withExceptions\(\s*function\s*\(\s*[\\\\\w]*Exceptions\s+\$exceptions\s*\)\s*(?::\s*void\s*)?\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['bootstrap/app.php (inside ->withExceptions())', $this->exceptionsSnippet()];

            return $contents;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);

        $this->wired[] = 'bootstrap/app.php: ApiExceptionHandler renders the uniform JSON error envelope';

        return substr($contents, 0, $at)."\n".$this->exceptionsSnippet()."\n".substr($contents, $at);
    }

    /*
    |----------------------------------------------------------------------
    | Laravel 10 wiring
    |----------------------------------------------------------------------
    */

    private function wireRouteServiceProvider(Filesystem $files): void
    {
        $path = app_path('Providers/RouteServiceProvider.php');

        if (! $files->exists($path)) {
            $this->manual[] = ['app/Providers/RouteServiceProvider.php', $this->routingGroupSnippet()];

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'routes/api/')) {
            $this->wired[] = 'RouteServiceProvider already loads routes/api/*.php';

            return;
        }

        $pattern = '/\$this->routes\(\s*function\s*\(\s*\)\s*(?::\s*void\s*)?\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['app/Providers/RouteServiceProvider.php (inside $this->routes())', $this->routingGroupSnippet()];

            return;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);
        $files->put($path, substr($contents, 0, $at)."\n".$this->routingGroupSnippet()."\n".substr($contents, $at));

        $this->wired[] = 'RouteServiceProvider: routes/api/{version}.php loaded under the api middleware group';
    }

    private function wireHttpKernel(Filesystem $files): void
    {
        $path = app_path('Http/Kernel.php');

        if (! $files->exists($path)) {
            $this->manual[] = ['app/Http/Kernel.php', $this->kernelSnippet()];

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'Security\\SecurityHeaders')) {
            $this->wired[] = 'app/Http/Kernel.php already registers the security middleware';

            return;
        }

        $global = '/protected \$middleware = \[/';
        $api = "/'api' => \[/";
        $patched = $contents;

        if (preg_match($global, $patched, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = $m[0][1] + strlen($m[0][0]);
            $patched = substr($patched, 0, $at)
                ."\n        \\App\\Http\\Middleware\\Security\\SecurityHeaders::class,"
                .substr($patched, $at);
        }

        if (preg_match($api, $patched, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = $m[0][1] + strlen($m[0][0]);
            $patched = substr($patched, 0, $at)
                ."\n            \\App\\Http\\Middleware\\Security\\ForceJsonResponse::class,"
                .substr($patched, $at);
        }

        if ($patched === $contents) {
            $this->manual[] = ['app/Http/Kernel.php', $this->kernelSnippet()];

            return;
        }

        $files->put($path, $patched);
        $this->wired[] = 'app/Http/Kernel.php: SecurityHeaders (global) and ForceJsonResponse (api group) registered';
    }

    private function wireLegacyExceptionHandler(Filesystem $files): void
    {
        $path = app_path('Exceptions/Handler.php');

        if (! $files->exists($path)) {
            $this->manual[] = ['app/Exceptions/Handler.php', $this->legacyExceptionsSnippet()];

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'ApiExceptionHandler')) {
            $this->wired[] = 'app/Exceptions/Handler.php already delegates to ApiExceptionHandler';

            return;
        }

        $pattern = '/public function register\(\)\s*(?::\s*void\s*)?\{/';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            $this->manual[] = ['app/Exceptions/Handler.php (inside register())', $this->legacyExceptionsSnippet()];

            return;
        }

        $at = $matches[0][1] + strlen($matches[0][0]);
        $files->put($path, substr($contents, 0, $at)."\n".$this->legacyExceptionsSnippet()."\n".substr($contents, $at));

        $this->wired[] = 'app/Exceptions/Handler.php: ApiExceptionHandler renders API failures';
    }

    /*
    |----------------------------------------------------------------------
    | Provider registration + repository bindings
    |----------------------------------------------------------------------
    */

    private function registerProviders(Filesystem $files): void
    {
        $providers = [
            'App\\Providers\\RepositoryServiceProvider',
            'App\\Providers\\SecurityServiceProvider',
        ];

        $path = base_path('bootstrap/providers.php');

        if ($files->exists($path)) {
            $contents = $files->get($path);

            foreach ($providers as $provider) {
                if (str_contains($contents, $provider)) {
                    continue;
                }

                $close = strrpos($contents, '];');

                if ($close === false) {
                    $this->manual[] = ['bootstrap/providers.php', '    '.$provider.'::class,'];

                    continue;
                }

                $contents = substr($contents, 0, $close).'    '.$provider."::class,\n".substr($contents, $close);
                $this->wired[] = 'bootstrap/providers.php: registered '.$provider;
            }

            $files->put($path, $contents);

            return;
        }

        // Laravel 10 fallback.
        $path = config_path('app.php');

        if (! $files->exists($path)) {
            foreach ($providers as $provider) {
                $this->manual[] = ['provider registration', $provider.'::class,'];
            }

            return;
        }

        $contents = $files->get($path);
        $anchor = 'App\\Providers\\RouteServiceProvider::class,';

        foreach ($providers as $provider) {
            if (str_contains($contents, $provider)) {
                continue;
            }

            if (! str_contains($contents, $anchor)) {
                $this->manual[] = ["config/app.php ('providers' array)", '    '.$provider.'::class,'];

                continue;
            }

            $contents = str_replace(
                $anchor,
                $anchor."\n        ".$provider.'::class,',
                $contents
            );
            $this->wired[] = "config/app.php: registered ".$provider;
        }

        $files->put($path, $contents);
    }

    /**
     * Append contract => implementation pairs below the marker comment in the
     * published RepositoryServiceProvider.
     *
     * @param  array<class-string|string, class-string|string>|null  $bindings
     */
    private function appendRepositoryBindings(Filesystem $files, ?array $bindings = null): void
    {
        $bindings ??= [
            'App\\Repositories\\Contracts\\UserRepositoryInterface' => 'App\\Repositories\\Eloquent\\EloquentUserRepository',
        ];

        $path = app_path('Providers/RepositoryServiceProvider.php');

        if (! $files->exists($path)) {
            return;
        }

        $contents = $files->get($path);
        $marker = '// cachewraith-template:bindings';

        if (! str_contains($contents, $marker)) {
            foreach ($bindings as $contract => $implementation) {
                if (! str_contains($contents, $contract)) {
                    $this->manual[] = [
                        'app/Providers/RepositoryServiceProvider.php ($bindings)',
                        '        \\'.$contract.'::class => \\'.$implementation.'::class,',
                    ];
                }
            }

            return;
        }

        $additions = '';

        foreach ($bindings as $contract => $implementation) {
            if (str_contains($contents, $contract)) {
                continue;
            }

            $additions .= "\n        \\".$contract.'::class => \\'.$implementation.'::class,';
            $this->wired[] = 'RepositoryServiceProvider: bound '.class_basename($contract);
        }

        if ($additions === '') {
            return;
        }

        $files->put($path, str_replace($marker, $marker.$additions, $contents));
    }

    /**
     * OWASP A02: leave the production reminder where it will actually be read.
     */
    private function noteEnvExample(Filesystem $files): void
    {
        $path = base_path('.env.example');

        if (! $files->exists($path)) {
            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'cachewraith-laravel-template-structure')) {
            return;
        }

        $files->append($path, <<<'ENV'

# --- cachewraith-laravel-template-structure -------------------------------------
# APP_DEBUG must be false in production: a debug response leaks file paths,
# SQL fragments and environment values (OWASP A02).
# APP_DEBUG=false
# APP_ENV=production
#
# CACHEWRAITH_SECURITY_HEADERS=true
# CACHEWRAITH_HSTS=true
# CACHEWRAITH_FORCE_HTTPS=true
# CACHEWRAITH_RATE_LIMITING=true
# CACHEWRAITH_API_PREFIX=api
# ------------------------------------------------------------------------
ENV);

        $this->wired[] = '.env.example: production hardening notes appended';
    }

    /*
    |----------------------------------------------------------------------
    | Snippets
    |----------------------------------------------------------------------
    */

    private function routingSnippet(): string
    {
        return <<<'PHP'
        then: function (): void {
            // Every configured API version is loaded from routes/api/{version}.php.
            // Adding v2 never means editing v1 (Open/Closed).
            $prefix = trim((string) config('cachewraith-template.api_version_prefix', 'api'), '/');

            foreach ((array) config('cachewraith-template.versions', ['v1']) as $version) {
                $file = base_path("routes/api/{$version}.php");

                if (file_exists($file)) {
                    \Illuminate\Support\Facades\Route::middleware('api')
                        ->prefix($prefix.'/'.$version)
                        ->group($file);
                }
            }
        },
PHP;
    }

    private function routingGroupSnippet(): string
    {
        return <<<'PHP'
            $prefix = trim((string) config('cachewraith-template.api_version_prefix', 'api'), '/');

            foreach ((array) config('cachewraith-template.versions', ['v1']) as $version) {
                $file = base_path("routes/api/{$version}.php");

                if (file_exists($file)) {
                    \Illuminate\Support\Facades\Route::middleware('api')
                        ->prefix($prefix.'/'.$version)
                        ->group($file);
                }
            }
PHP;
    }

    private function middlewareSnippet(): string
    {
        return <<<'PHP'
        // Runs before auth and validation so error paths stay JSON (A02/A10).
        $middleware->api(prepend: [
            \App\Http\Middleware\Security\ForceJsonResponse::class,
        ]);

        // CSP, framing, MIME-sniffing, referrer and HSTS headers (A02).
        $middleware->append(\App\Http\Middleware\Security\SecurityHeaders::class);
PHP;
    }

    private function exceptionsSnippet(): string
    {
        return <<<'PHP'
        // Uniform JSON error envelope: {success, message, errors, code}.
        \App\Exceptions\ApiExceptionHandler::register($exceptions);
PHP;
    }

    private function legacyExceptionsSnippet(): string
    {
        return <<<'PHP'
        $this->renderable(function (\Throwable $e, $request) {
            return (new \App\Exceptions\ApiExceptionHandler())->render($request, $e);
        });
PHP;
    }

    private function kernelSnippet(): string
    {
        return <<<'PHP'
// protected $middleware = [
        \App\Http\Middleware\Security\SecurityHeaders::class,
// ];

// 'api' => [
            \App\Http\Middleware\Security\ForceJsonResponse::class,
// ],
PHP;
    }

    /*
    |----------------------------------------------------------------------
    | Helpers
    |----------------------------------------------------------------------
    */

    private function isLaravel11OrAbove(): bool
    {
        return version_compare($this->laravel->version(), '11.0.0', '>=');
    }

    /**
     * Offset of the parenthesis matching the one at $openAt, ignoring parens
     * inside strings and comments (bootstrap/app.php is full of both).
     */
    private function matchingParen(string $contents, int $openAt): ?int
    {
        $depth = 0;
        $length = strlen($contents);

        for ($i = $openAt; $i < $length; $i++) {
            $char = $contents[$i];

            if ($char === "'" || $char === '"') {
                $i = $this->skipString($contents, $i);

                continue;
            }

            if ($char === '/' && $i + 1 < $length && ($contents[$i + 1] === '/' || $contents[$i + 1] === '*')) {
                $i = $this->skipComment($contents, $i);

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function skipString(string $contents, int $start): int
    {
        $quote = $contents[$start];
        $length = strlen($contents);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($contents[$i] === '\\') {
                $i++;

                continue;
            }

            if ($contents[$i] === $quote) {
                return $i;
            }
        }

        return $length;
    }

    private function skipComment(string $contents, int $start): int
    {
        if ($contents[$start + 1] === '/') {
            $end = strpos($contents, "\n", $start);

            return $end === false ? strlen($contents) : $end;
        }

        $end = strpos($contents, '*/', $start);

        return $end === false ? strlen($contents) : $end + 1;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }

    private function summary(): void
    {
        $this->newLine();

        if ($this->created !== []) {
            $this->components->info('Created '.count($this->created).' file(s):');
            foreach ($this->created as $file) {
                $this->line('    <fg=green>+</> '.$file);
            }
        }

        if ($this->skipped !== []) {
            $this->newLine();
            $this->components->warn('Left alone (already present — re-run with --force to overwrite):');
            foreach ($this->skipped as $file) {
                $this->line('    <fg=yellow>=</> '.$file);
            }
        }

        if ($this->wired !== []) {
            $this->newLine();
            $this->components->info('Wired up:');
            foreach ($this->wired as $note) {
                $this->line('    <fg=green>></> '.$note);
            }
        }

        if ($this->manual !== []) {
            $this->newLine();
            $this->components->warn('Could not patch these automatically — add the snippet by hand:');
            foreach ($this->manual as [$where, $snippet]) {
                $this->newLine();
                $this->line('  <options=bold>'.$where.'</>');
                $this->line('<fg=gray>'.$snippet.'</>');
            }
        }

        $this->newLine();
        $this->components->info('Next steps');
        $this->line('    1. <options=bold>composer dump-autoload</>');
        $this->line('    2. <options=bold>php artisan migrate</>');
        $this->line('    3. <options=bold>php artisan install:api</> <fg=gray>(Laravel 11+ — installs Sanctum, needed by the auth routes)</>');
        $this->line('    4. Add <options=bold>Laravel\Sanctum\HasApiTokens</> to App\Models\User');
        $this->line('    5. <options=bold>php artisan route:list --path=api</> <fg=gray>(you should see api/v1/users)</>');
        $this->line('    6. <options=bold>composer audit</> <fg=gray>(OWASP A03 — wire this into CI)</>');
        $this->newLine();
    }
}
