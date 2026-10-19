<?php

namespace App\Http\Controllers;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

/**
 * Read-only health report for status.michael.computer. Contract: status-health/1, revision 7.
 * Version 2.0.1. No Composer dependencies. Supports Laravel 10 and later, PHP 8.1 and later.
 *
 * Copy to app/Http/Controllers/StatusHealthController.php. Setup steps are in README.md, which
 * accompanies this file.
 *
 * Property-specific checks and metrics go in groups() at the end of this class. Do not change
 * anything else in this file; report anything that does not fit to Michael.
 */
class StatusHealthController
{
    private const SCHEMA = 'status-health/1';
    private const RANK = ['healthy' => 1, 'inactive' => 1, 'unknown' => 2, 'warning' => 3, 'critical' => 4];

    // ---------------------------------------------------------------------------------------------
    // Scheduler marker and heartbeats (called from the scheduler, not from the endpoint)

    public static function schedulerMarkerPath(): string
    {
        return storage_path('framework/status-health-scheduler');
    }

    /** Call from the scheduler every minute. */
    public static function recordSchedulerRun(): void
    {
        file_put_contents(self::schedulerMarkerPath(), (string) time(), LOCK_EX);
    }

    /**
     * Heartbeat ping for a scheduled task, meeting contract section 11.2: 3-second connect timeout,
     * 5-second total timeout, every error caught. Sends nothing if no URL is configured.
     *
     *   ->onSuccess(StatusHealthController::heartbeat('ingest_ics'))
     *   ->onFailure(StatusHealthController::heartbeat('ingest_ics', failed: true))
     */
    public static function heartbeat(string $job, bool $failed = false): Closure
    {
        return static function () use ($job, $failed): void {
            try {
                $url = (string) config("services.status_health.heartbeats.{$job}");
                if ($url === '') {
                    return;
                }
                Http::connectTimeout(3)->timeout(5)->get($failed ? rtrim($url, '/') . '/fail' : $url);
            } catch (Throwable $e) {
                // A heartbeat must never fail or delay the task (contract section 11.2).
            }
        };
    }

    // ---------------------------------------------------------------------------------------------
    // Endpoint

    public function __invoke(Request $request): JsonResponse
    {
        // No hash configured: the endpoint is disabled and answers like any unknown path (contract
        // section 4). Outside the try block, so the 404 is not turned into a 500.
        if (!$this->enabled()) {
            abort(404);
        }
        try {
            $token = $this->presentedToken($request);
            if ($token === null) {
                return $this->respond(['error' => 'unauthorized', 'reason' => 'missing'], 401);
            }
            if (!$this->tokenValid($token)) {
                return $this->respond(['error' => 'unauthorized', 'reason' => 'invalid'], 401);
            }
            return $this->respond($this->report(), 200);
        } catch (Throwable $e) {
            return $this->respond(['error' => 'report_failed'], 500);
        }
    }

    private function respond(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ], JSON_UNESCAPED_SLASHES);
    }

    private function presentedToken(Request $request): ?string
    {
        $token = trim((string) $request->bearerToken());
        if ($token === '') {
            $token = trim((string) $request->header('X-Status-Token'));
        }
        return $token === '' ? null : $token;
    }

    private function enabled(): bool
    {
        // As in the WordPress and static implementations: the main hash switches the endpoint on.
        return trim((string) config('services.status_health.token_hash')) !== '';
    }

    private function tokenValid(string $token): bool
    {
        if (!preg_match('/^[A-Fa-f0-9]{64}$/', $token)) {
            return false;
        }
        $presented = hash('sha256', strtolower($token));
        foreach (['token_hash', 'token_hash_next'] as $key) {
            $expected = strtolower((string) config("services.status_health.{$key}"));
            if ($expected !== '' && hash_equals($expected, $presented)) {
                return true;
            }
        }
        return false;
    }

    private function report(): array
    {
        $checks = [];
        $metrics = [];

        if ($this->usesDatabase()) {
            $checks[] = $this->run('database', 'Database', fn () => $this->checkDatabase(null));
            foreach ($this->extraConnections() as $name) {
                $checks[] = $this->run("database_{$name}", "Database ({$name})", fn () => $this->checkDatabase($name));
            }
            $checks[] = $this->run('migrations_pending', 'Pending migrations', fn () => $this->checkMigrations());
        }
        if (config('services.status_health.scheduler')) {
            $checks[] = $this->run('scheduler', 'Scheduler', fn () => $this->checkScheduler());
        }
        if (config('services.status_health.queue')) {
            $checks[] = $this->run('queue_failed', 'Failed jobs', fn () => $this->checkFailedJobs());
            $checks[] = $this->run('queue_pending', 'Pending jobs', fn () => $this->checkPendingJobs());
        }
        $checks[] = $this->run('storage_writable', 'Storage writable', fn () => $this->checkStorage());
        $checks[] = $this->run('debug_mode', 'Debug mode', fn () => $this->checkDebugMode());
        $checks[] = $this->run('app_env', 'Environment', fn () => $this->checkEnvironment());
        $checks[] = $this->run('php_version_expected', 'PHP version', fn () => $this->checkPhpVersion());
        $checks[] = $this->run('vite_manifest', 'Vite build', fn () => $this->checkViteManifest());
        $checks[] = $this->run('config_cache_stale', 'Cached configuration', fn () => $this->checkCacheStale());
        $checks[] = $this->run('composer_install_pending', 'Composer install', fn () => $this->checkComposerInstall());
        $checks[] = $this->run('log_errors', 'Log errors', fn () => $this->checkLogErrors());

        $logSize = $this->currentLogFile();
        $metrics[] = $this->metric('log_size', 'Log file size', $logSize ? (int) filesize($logSize) : null, 'bytes');

        [$groupChecks, $groupMetrics] = $this->runGroups();
        $checks = array_merge($checks, $groupChecks);
        $metrics = array_merge($metrics, $groupMetrics);

        // Checks that do not apply on this app (for example no Vite build) are left out.
        $checks = array_values(array_filter($checks, fn ($c) => $c !== null));

        try {
            $stack = $this->stack();
        } catch (Throwable $e) {
            $stack = null;
        }

        return [
            'schema' => self::SCHEMA,
            'property' => config('services.status_health.property'),
            'environment' => $this->reportedEnvironment(),
            'generated_at' => $this->now(),
            'status' => $this->worst($checks),
            'stack' => $stack,
            'checks' => $checks,
            'metrics' => $metrics,
        ];
    }

    private function reportedEnvironment(): string
    {
        return (string) (config('services.status_health.environment') ?: app()->environment());
    }

    // ---------------------------------------------------------------------------------------------
    // Standard checks (contract section 7)

    private function checkDatabase(?string $connection): array
    {
        $start = microtime(true);
        DB::connection($connection)->select('select 1');
        $ms = (int) round((microtime(true) - $start) * 1000);
        return [
            'status' => $ms > 500 ? 'warning' : 'healthy',
            'value' => $ms,
            'unit' => 'ms',
            'message' => $ms > 500 ? "Database responded slowly ({$ms} ms)" : null,
        ];
    }

    private function checkMigrations(): array
    {
        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        if (!$repository->repositoryExists()) {
            return ['status' => 'critical', 'message' => 'Migrations table does not exist'];
        }
        $paths = array_merge([database_path('migrations')], $migrator->paths());
        $files = array_keys($migrator->getMigrationFiles($paths));
        $pending = count(array_diff($files, $repository->getRan()));
        return [
            'status' => $pending > 0 ? 'critical' : 'healthy',
            'value' => $pending,
            'unit' => 'count',
            'message' => $pending > 0 ? "{$pending} " . ($pending === 1 ? 'migration' : 'migrations') . ' not run' : null,
        ];
    }

    private function checkScheduler(): array
    {
        $path = self::schedulerMarkerPath();
        if (!is_file($path)) {
            return ['status' => 'critical', 'message' => 'Scheduler has never run'];
        }
        $last = (int) file_get_contents($path);
        $age = max(0, time() - $last);
        $status = $age > 900 ? 'critical' : ($age > 300 ? 'warning' : 'healthy');
        return [
            'status' => $status,
            'value' => $age,
            'unit' => 'seconds',
            'message' => $status === 'healthy' ? null : 'Scheduler last ran ' . $this->ago($age) . ' ago',
        ];
    }

    private function checkFailedJobs(): array
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $connection = config('queue.failed.database');
        if (!Schema::connection($connection)->hasTable($table)) {
            return ['status' => 'unknown', 'message' => 'Failed jobs table not found'];
        }
        $count = DB::connection($connection)->table($table)->count();
        $status = $count > 10 ? 'critical' : ($count > 0 ? 'warning' : 'healthy');
        return [
            'status' => $status,
            'value' => $count,
            'unit' => 'jobs',
            'message' => $count > 0 ? "{$count} failed " . ($count === 1 ? 'job' : 'jobs') : null,
        ];
    }

    private function checkPendingJobs(): array
    {
        $count = Queue::size();
        $status = $count > 1000 ? 'critical' : ($count > 100 ? 'warning' : 'healthy');
        return [
            'status' => $status,
            'value' => $count,
            'unit' => 'jobs',
            'message' => $status === 'healthy' ? null : "{$count} jobs waiting in the queue",
        ];
    }

    private function checkStorage(): array
    {
        $file = storage_path('framework/status-health-write-test-' . bin2hex(random_bytes(4)));
        $ok = @file_put_contents($file, 'ok') === 2;
        @unlink($file);
        return $ok
            ? ['status' => 'healthy']
            : ['status' => 'critical', 'message' => 'storage/framework is not writable'];
    }

    private function checkDebugMode(): array
    {
        $environment = app()->environment();
        if (!in_array($environment, ['local', 'testing'], true) && config('app.debug')) {
            return ['status' => 'critical', 'message' => "APP_DEBUG is on in {$environment}"];
        }
        return ['status' => 'healthy'];
    }

    private function checkEnvironment(): array
    {
        $actual = app()->environment();
        $reported = $this->reportedEnvironment();
        if ($actual !== $reported) {
            return ['status' => 'warning', 'message' => "APP_ENV is {$actual}, expected {$reported}"];
        }
        return ['status' => 'healthy'];
    }

    private function checkPhpVersion(): array
    {
        $expected = $this->expectedPhpVersion();
        $actual = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if ($expected === null) {
            return ['status' => 'inactive', 'message' => 'Expected PHP version not set'];
        }
        if ($expected !== $actual) {
            return ['status' => 'critical', 'message' => "Running PHP {$actual}, expected {$expected}"];
        }
        return ['status' => 'healthy'];
    }

    /** @return array|null null when the app has no Vite build, so the check is left out */
    private function checkViteManifest(): ?array
    {
        $build = public_path('build');
        if (!is_dir($build)) {
            return null;
        }
        $manifestPath = null;
        foreach ([$build . '/manifest.json', $build . '/.vite/manifest.json'] as $candidate) {
            if (is_file($candidate)) {
                $manifestPath = $candidate;
                break;
            }
        }
        if ($manifestPath === null) {
            return ['status' => 'critical', 'message' => 'Vite manifest is missing'];
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return ['status' => 'critical', 'message' => 'Vite manifest cannot be read'];
        }
        $missing = 0;
        foreach ($manifest as $chunk) {
            if (!is_array($chunk) || empty($chunk['isEntry'])) {
                continue;
            }
            foreach (array_merge([(string) ($chunk['file'] ?? '')], (array) ($chunk['css'] ?? [])) as $file) {
                if ($file !== '' && !is_file($build . '/' . ltrim($file, '/'))) {
                    $missing++;
                }
            }
        }
        return $missing > 0
            ? ['status' => 'critical', 'value' => $missing, 'unit' => 'count', 'message' => "{$missing} Vite entry " . ($missing === 1 ? 'file is' : 'files are') . ' missing']
            : ['status' => 'healthy'];
    }

    private function checkCacheStale(): array
    {
        $deployedAt = $this->deployInfo()['deployed_at_unix'];
        $files = array_merge(
            [base_path('bootstrap/cache/config.php'), base_path('bootstrap/cache/events.php')],
            glob(base_path('bootstrap/cache/routes*.php')) ?: []
        );
        $files = array_values(array_filter($files, 'is_file'));
        if ($files === []) {
            return ['status' => 'inactive', 'message' => 'Nothing is cached'];
        }
        if ($deployedAt === null) {
            return ['status' => 'inactive', 'message' => 'Deploy time unknown'];
        }
        $stale = 0;
        foreach ($files as $file) {
            if (filemtime($file) < $deployedAt) {
                $stale++;
            }
        }
        return $stale > 0
            ? ['status' => 'warning', 'value' => $stale, 'unit' => 'count', 'message' => "{$stale} cached " . ($stale === 1 ? 'file is' : 'files are') . ' older than the last deploy']
            : ['status' => 'healthy'];
    }

    private function checkComposerInstall(): array
    {
        $lock = $this->readJson(base_path('composer.lock'));
        if (!is_array($lock) || !isset($lock['packages'])) {
            return ['status' => 'inactive', 'message' => 'composer.lock not found'];
        }
        $installed = [];
        foreach ($this->installedPackages() as $package) {
            $installed[$package['name']] = $package['version'];
        }
        $mismatches = 0;
        foreach ($lock['packages'] as $package) {
            $name = $package['name'] ?? null;
            if ($name !== null && ($installed[$name] ?? null) !== ($package['version'] ?? null)) {
                $mismatches++;
            }
        }
        return $mismatches > 0
            ? ['status' => 'critical', 'value' => $mismatches, 'unit' => 'count', 'message' => "{$mismatches} " . ($mismatches === 1 ? 'package differs' : 'packages differ') . ' from composer.lock; run composer install']
            : ['status' => 'healthy', 'value' => 0, 'unit' => 'count'];
    }

    private function checkLogErrors(): array
    {
        return $this->cached('log_errors', 300, function () {
            $file = $this->currentLogFile();
            if ($file === null) {
                return ['status' => 'healthy', 'value' => 0, 'unit' => 'count', 'message' => null];
            }
            $size = (int) filesize($file);
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                return ['status' => 'unknown', 'message' => 'Log file cannot be read'];
            }
            $read = min($size, 2 * 1024 * 1024);
            fseek($handle, $size - $read);
            $tail = (string) fread($handle, $read);
            fclose($handle);

            $zone = new DateTimeZone((string) config('app.timezone', 'UTC'));
            $now = time();
            $hour = 0;
            $day = 0;
            if (preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/m', $tail, $matches)) {
                foreach ($matches[1] as $stamp) {
                    $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', str_replace('T', ' ', $stamp), $zone);
                    if ($time === false) {
                        continue;
                    }
                    $age = $now - $time->getTimestamp();
                    if ($age <= 86400) {
                        $day++;
                        if ($age <= 3600) {
                            $hour++;
                        }
                    }
                }
            }
            $status = $hour > 50 ? 'critical' : (($hour > 0 || $day > 10) ? 'warning' : 'healthy');
            return [
                'status' => $status,
                'value' => $hour,
                'unit' => 'count',
                'message' => $status === 'healthy' ? null : "{$hour} errors in the last hour, {$day} in 24 hours",
            ];
        }, 900);
    }

    // ---------------------------------------------------------------------------------------------
    // Property-specific groups (contract section 9.2)

    private function runGroups(): array
    {
        $checks = [];
        $metrics = [];
        $disabled = $this->disabledGroups();
        foreach ($this->safeGroups() as $name => $group) {
            $keys = (array) ($group['keys'] ?? []);
            if (in_array($name, $disabled, true)) {
                foreach ($keys as $key => $label) {
                    $checks[] = $this->normalize(['key' => $key, 'label' => $label, 'status' => 'inactive', 'message' => 'Disabled on this copy']);
                }
                continue;
            }
            try {
                $reported = [];
                foreach ((array) (isset($group['checks']) ? ($group['checks'])() : []) as $check) {
                    if (isset($check['key'], $check['label'], $check['status'])) {
                        $checks[] = $this->normalize($check);
                        $reported[$check['key']] = true;
                    }
                }
                foreach ($keys as $key => $label) {
                    if (!isset($reported[$key])) {
                        $checks[] = $this->normalize(['key' => $key, 'label' => $label, 'status' => 'unknown', 'message' => 'Check did not report']);
                    }
                }
            } catch (Throwable $e) {
                foreach ($keys as $key => $label) {
                    $checks[] = $this->normalize(['key' => $key, 'label' => $label, 'status' => 'unknown', 'message' => 'Check failed to run']);
                }
            }
            try {
                foreach ((array) (isset($group['metrics']) ? ($group['metrics'])() : []) as $metric) {
                    if (isset($metric['key'], $metric['label'])) {
                        $metrics[] = $this->metric($metric['key'], $metric['label'], $metric['value'] ?? null, $metric['unit'] ?? null, $metric['observed_at'] ?? null, $metric['stale_after'] ?? null);
                    }
                }
            } catch (Throwable $e) {
                // Metrics that fail are left out; checks carry the alerting.
            }
        }
        return [$checks, $metrics];
    }

    private function safeGroups(): array
    {
        try {
            return $this->groups();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function disabledGroups(): array
    {
        return $this->csvConfig('services.status_health.disabled_groups');
    }

    /**
     * Report a check from a job status file (contract section 11.1). Use inside a group's checks.
     * Files are read from STATUS_HEALTH_STATUS_DIR, named <job>.json.
     */
    protected function jobCheck(string $job, string $label, ?int $warnAfterSeconds = null, ?int $criticalAfterSeconds = null, int $criticalFailures = 3): array
    {
        $base = ['key' => "job_{$job}", 'label' => $label];
        $dir = rtrim((string) config('services.status_health.status_dir'), '/');
        if ($dir === '' || !preg_match('/^[a-z0-9_]+$/', $job)) {
            return $base + ['status' => 'unknown', 'message' => 'Job status folder not configured'];
        }
        $data = $this->readJson("{$dir}/{$job}.json");
        if (!is_array($data)) {
            return $base + ['status' => 'unknown', 'message' => 'Job status file missing or unreadable'];
        }
        $interval = (int) ($data['interval_seconds'] ?? 0);
        $warnAfter = $warnAfterSeconds ?? ($interval > 0 ? 2 * $interval : null);
        $criticalAfter = $criticalAfterSeconds ?? ($interval > 0 ? 4 * $interval : null);
        $lastSuccess = isset($data['last_success_at']) ? strtotime((string) $data['last_success_at']) : false;
        $age = $lastSuccess ? max(0, time() - $lastSuccess) : null;
        $failures = (int) ($data['consecutive_failures'] ?? 0);
        $failed = ($data['last_status'] ?? '') === 'failure';

        if ($age === null) {
            $status = 'critical';
            $message = 'Never succeeded';
        } elseif ($failures >= $criticalFailures || ($criticalAfter !== null && $age > $criticalAfter)) {
            $status = 'critical';
            $message = $failures >= $criticalFailures ? "{$failures} failed runs in a row" : 'Last success ' . $this->ago($age) . ' ago';
        } elseif ($failed || ($warnAfter !== null && $age > $warnAfter)) {
            $status = 'warning';
            $message = $failed ? 'Last run failed' : 'Last success ' . $this->ago($age) . ' ago';
        } else {
            $status = 'healthy';
            $message = null;
        }
        return $base + [
            'status' => $status,
            'value' => $age === null ? null : round($age / 3600, 1),
            'unit' => 'hours',
            'message' => $message,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Stack inventory (contract section 5.4)

    private function stack(): array
    {
        $deploy = $this->deployInfo();
        $stack = [
            'platform' => 'laravel',
            'platform_version' => app()->version(),
            'php' => $this->phpInfo(),
            'database' => $this->databaseInfo(),
            'server' => [
                'software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
                'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            ],
            'deploy' => ['commit' => $deploy['commit'], 'deployed_at' => $deploy['deployed_at']],
            'laravel' => [
                'environment' => app()->environment(),
                'timezone' => (string) config('app.timezone'),
                'drivers' => [
                    'cache' => config('cache.default'),
                    'queue' => config('queue.default'),
                    'session' => config('session.driver'),
                    'mail' => config('mail.default') ?? config('mail.driver'),
                    'filesystem' => config('filesystems.default'),
                ],
            ],
            'packages' => $this->installedPackages(),
            'stack_observed_at' => $this->now(),
        ];
        $frontend = $this->frontendPackages();
        if ($frontend !== null) {
            $stack['frontend_packages'] = $frontend;
        }
        return $stack;
    }

    private function phpInfo(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING | SORT_FLAG_CASE);
        return [
            'version' => PHP_VERSION,
            'expected' => $this->expectedPhpVersion(),
            'sapi' => PHP_SAPI,
            'extensions' => $extensions,
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'opcache' => extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function expectedPhpVersion(): ?string
    {
        $configured = trim((string) config('services.status_health.php_version'));
        if ($configured !== '') {
            return $configured;
        }
        $composer = $this->readJson(base_path('composer.json'));
        $platform = (string) ($composer['config']['platform']['php'] ?? '');
        if (preg_match('/^(\d+)\.(\d+)/', $platform, $m)) {
            return "{$m[1]}.{$m[2]}";
        }
        return null;
    }

    private function databaseInfo(): array
    {
        $info = ['driver' => null, 'server_version' => null, 'connections' => [], 'tables' => []];
        if (!$this->usesDatabase()) {
            return $info;
        }
        [$info['driver'], $info['server_version']] = $this->connectionInfo(null);
        foreach ($this->extraConnections() as $name) {
            [$driver, $version] = $this->connectionInfo($name);
            $info['connections'][] = ['name' => $name, 'driver' => $driver, 'server_version' => $version];
        }
        $tables = [];
        if (config('cache.default') === 'database') {
            $tables[] = [config('cache.stores.database.connection'), (string) config('cache.stores.database.table', 'cache')];
        }
        if (config('session.driver') === 'database') {
            $tables[] = [config('session.connection'), (string) config('session.table', 'sessions')];
        }
        foreach ($tables as [$connection, $table]) {
            $info['tables'][] = $this->tableEstimate($connection, $table);
        }
        return $info;
    }

    private function connectionInfo(?string $name): array
    {
        try {
            $connection = DB::connection($name);
            $driver = $connection->getDriverName();
            $version = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            if ($driver === 'mysql' && stripos($version, 'mariadb') !== false) {
                $driver = 'mariadb';
            }
            return [$driver, $version];
        } catch (Throwable $e) {
            return [config('database.connections.' . ($name ?? config('database.default')) . '.driver'), null];
        }
    }

    private function tableEstimate(?string $connection, string $table): array
    {
        $result = ['connection' => $connection ?? (string) config('database.default'), 'table' => $table, 'rows_estimate' => null, 'bytes_estimate' => null];
        try {
            $db = DB::connection($connection);
            if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                $row = $db->selectOne(
                    'select table_rows as r, data_length + index_length as b from information_schema.tables where table_schema = database() and table_name = ?',
                    [$db->getTablePrefix() . $table]
                );
                if ($row) {
                    $result['rows_estimate'] = (int) $row->r;
                    $result['bytes_estimate'] = (int) $row->b;
                }
            }
        } catch (Throwable $e) {
            // Estimates stay null.
        }
        return $result;
    }

    /** @return array{commit: ?string, deployed_at: ?string, deployed_at_unix: ?int} */
    private function deployInfo(): array
    {
        $none = ['commit' => null, 'deployed_at' => null, 'deployed_at_unix' => null];
        $git = base_path('.git');
        if (!is_dir($git) || !is_file("{$git}/HEAD")) {
            return $none;
        }
        $head = trim((string) file_get_contents("{$git}/HEAD"));
        if (preg_match('/^[0-9a-f]{40}$/', $head)) {
            $ref = null;
            $commit = $head;
        } elseif (preg_match('#^ref: (refs/[A-Za-z0-9._/-]+)$#', $head, $m) && !str_contains($m[1], '..')) {
            $ref = $m[1];
            $commit = is_file("{$git}/{$ref}") ? trim((string) file_get_contents("{$git}/{$ref}")) : null;
            if ($commit === null && is_file("{$git}/packed-refs")) {
                foreach (file("{$git}/packed-refs", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (preg_match('/^([0-9a-f]{40}) (.+)$/', $line, $p) && $p[2] === $ref) {
                        $commit = $p[1];
                        break;
                    }
                }
            }
        } else {
            return $none;
        }
        $time = null;
        $reflog = $ref ? "{$git}/logs/{$ref}" : "{$git}/logs/HEAD";
        if (is_file($reflog)) {
            $lines = file($reflog, FILE_IGNORE_NEW_LINES) ?: [];
            $last = end($lines);
            // Format: <old> <new> <name> <email> <unix time> <zone>\t<message>. Only the time is read.
            if (is_string($last) && preg_match('/ (\d{9,11}) [+-]\d{4}\t/', $last, $t)) {
                $time = (int) $t[1];
            }
        }
        if ($time === null && $ref && is_file("{$git}/{$ref}")) {
            $time = (int) filemtime("{$git}/{$ref}");
        }
        return [
            'commit' => ($commit !== null && preg_match('/^[0-9a-f]{40}$/', $commit)) ? $commit : null,
            'deployed_at' => $time ? gmdate('Y-m-d\TH:i:s\Z', $time) : null,
            'deployed_at_unix' => $time,
        ];
    }

    private function installedPackages(): array
    {
        $path = base_path('vendor/composer/installed.json');
        return $this->cachedByFile('packages', $path, function () use ($path) {
            $data = $this->readJson($path);
            if (!is_array($data)) {
                return [];
            }
            // Composer 2 wraps the list in "packages"; Composer 1 is a plain list.
            $packages = $data['packages'] ?? $data;
            $devNames = $data['dev-package-names'] ?? [];
            $out = [];
            foreach ($packages as $package) {
                if (isset($package['name'])) {
                    $out[] = [
                        'name' => $package['name'],
                        'version' => $package['version'] ?? null,
                        'dev' => in_array($package['name'], $devNames, true),
                    ];
                }
            }
            usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));
            return $out;
        });
    }

    private function frontendPackages(): ?array
    {
        $path = base_path('package-lock.json');
        if (!is_file($path)) {
            return null;
        }
        return $this->cachedByFile('frontend_packages', $path, function () use ($path) {
            $lock = $this->readJson($path);
            $out = [];
            foreach ((array) ($lock['packages'] ?? []) as $location => $package) {
                if ($location === '' || !is_array($package) || !str_contains($location, 'node_modules/')) {
                    continue;
                }
                $name = $package['name'] ?? substr($location, strrpos($location, 'node_modules/') + 13);
                $out[] = [
                    'name' => $name,
                    'version' => $package['version'] ?? null,
                    'dev' => !empty($package['dev']) || !empty($package['devOptional']),
                    'optional' => !empty($package['optional']) || !empty($package['devOptional']),
                ];
            }
            usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']) ?: strcmp((string) $a['version'], (string) $b['version']));
            return $out;
        });
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers

    private function usesDatabase(): bool
    {
        return (bool) config('services.status_health.database', true) && config('database.default') !== null;
    }

    private function extraConnections(): array
    {
        return array_values(array_filter(
            $this->csvConfig('services.status_health.connections'),
            fn ($n) => preg_match('/^[A-Za-z0-9_]+$/', $n)
        ));
    }

    private function csvConfig(string $key): array
    {
        $value = config($key, []);
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $value), fn ($v) => $v !== ''));
    }

    private function currentLogFile(): ?string
    {
        $files = glob(storage_path('logs/*.log')) ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        return $files[0];
    }

    private function readJson(string $path): mixed
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true);
    }

    private function cacheDir(): string
    {
        $dir = storage_path('framework/status-health-cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /**
     * Caches a check result for $seconds. The returned check carries the cached observed_at and the
     * given stale_after (contract section 10).
     */
    protected function cached(string $name, int $seconds, callable $compute, ?int $staleAfter = null): array
    {
        $file = $this->cacheDir() . '/' . preg_replace('/[^a-z0-9_]/', '_', $name) . '.json';
        $cached = $this->readJson($file);
        if (is_array($cached) && isset($cached['at'], $cached['result']) && time() - (int) $cached['at'] < $seconds) {
            $result = $cached['result'];
            $at = (int) $cached['at'];
        } else {
            $result = $compute();
            $at = time();
            @file_put_contents($file . '.tmp', json_encode(['at' => $at, 'result' => $result]), LOCK_EX);
            @rename($file . '.tmp', $file);
        }
        return $result + ['observed_at' => gmdate('Y-m-d\TH:i:s\Z', $at), 'stale_after' => $staleAfter ?? $seconds * 2];
    }

    /** Caches a parsed file until the file changes. */
    private function cachedByFile(string $name, string $path, callable $compute): array
    {
        if (!is_file($path)) {
            return [];
        }
        $key = filemtime($path) . ':' . filesize($path);
        $file = $this->cacheDir() . "/{$name}.json";
        $cached = $this->readJson($file);
        if (is_array($cached) && ($cached['key'] ?? null) === $key && is_array($cached['value'] ?? null)) {
            return $cached['value'];
        }
        $value = $compute();
        @file_put_contents($file . '.tmp', json_encode(['key' => $key, 'value' => $value]), LOCK_EX);
        @rename($file . '.tmp', $file);
        return $value;
    }

    /** Runs one check; an exception makes that check unknown instead of failing the report. */
    private function run(string $key, string $label, callable $fn): ?array
    {
        try {
            $result = $fn();
            if ($result === null) {
                return null;
            }
            return $this->normalize(array_merge(['key' => $key, 'label' => $label], $result));
        } catch (Throwable $e) {
            return $this->normalize(['key' => $key, 'label' => $label, 'status' => 'unknown', 'message' => 'Check failed to run']);
        }
    }

    private function normalize(array $check): array
    {
        $status = array_key_exists((string) ($check['status'] ?? ''), self::RANK) ? $check['status'] : 'unknown';
        return [
            'key' => (string) $check['key'],
            'label' => (string) $check['label'],
            'status' => $status,
            'observed_at' => $check['observed_at'] ?? $this->now(),
            'stale_after' => isset($check['stale_after']) ? (int) $check['stale_after'] : null,
            'value' => $check['value'] ?? null,
            'unit' => $check['unit'] ?? null,
            'message' => $check['message'] ?? null,
        ];
    }

    private function metric(string $key, string $label, int|float|null $value, ?string $unit, ?string $observedAt = null, ?int $staleAfter = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'observed_at' => $observedAt ?? $this->now(),
            'stale_after' => $staleAfter,
        ];
    }

    private function worst(array $checks): string
    {
        $worst = 'healthy';
        foreach ($checks as $check) {
            if (self::RANK[$check['status']] > self::RANK[$worst]) {
                $worst = $check['status'];
            }
        }
        return $worst;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function ago(int $seconds): string
    {
        if ($seconds < 120) {
            return "{$seconds} seconds";
        }
        if ($seconds < 7200) {
            return intdiv($seconds, 60) . ' minutes';
        }
        if ($seconds < 172800) {
            return intdiv($seconds, 3600) . ' hours';
        }
        return intdiv($seconds, 86400) . ' days';
    }

    // ---------------------------------------------------------------------------------------------
    // Property-specific checks and metrics

    /**
     * Property-specific checks and metrics, in named groups (contract section 9.2). Each group:
     *
     *   'keys'    => [check key => label, ...]   every check key the group can report
     *   'checks'  => fn () => [check, ...]       each check: key, label, status, and optionally
     *                                            observed_at, stale_after, value, unit, message
     *   'metrics' => fn () => [metric, ...]      each metric: key, label, value, unit
     *
     * A group listed in STATUS_HEALTH_DISABLED_GROUPS reports each of its keys as inactive, "Disabled
     * on this copy". A key listed in 'keys' but not returned by 'checks' is reported as unknown.
     * Times are UTC. Slow checks use $this->cached(). Job status files use $this->jobCheck().
     *
     * Example:
     *   return [
     *       'ingestion' => [
     *           'keys' => ['events_upcoming_published' => 'Upcoming published events'],
     *           'checks' => fn () => [[
     *               'key' => 'events_upcoming_published',
     *               'label' => 'Upcoming published events',
     *               'status' => ($n = \App\Models\Event::upcoming()->published()->count()) > 0 ? 'healthy' : 'warning',
     *               'value' => $n,
     *               'unit' => 'count',
     *               'message' => $n > 0 ? null : 'No upcoming published events',
     *           ]],
     *           'metrics' => fn () => [],
     *       ],
     *   ];
     */
    protected function groups(): array
    {
        // Mojave River Valley. The measuring lives in App\Support\StatusHealth\MrvChecks (testable with a fixed clock);
        // this method only arranges it into groups. A group in STATUS_HEALTH_DISABLED_GROUPS is reported inactive on that copy.
        $environment = $this->reportedEnvironment();
        $production = $environment === 'production';
        $mrv = new \App\Support\StatusHealth\MrvChecks(\Carbon\CarbonImmutable::now('UTC'));

        // Checks that query the weather database are cached for 5 minutes; the dashboard treats a result as stale after 15.
        $weather = fn (string $key, string $label, callable $check) => $this->cached($key, 300, fn () => $mrv->guard($key, $label, $check), 900);
        $guard = fn (string $key, string $label, callable $check) => $mrv->guard($key, $label, $check);
        // A metric that cannot be read is left out; it never takes the group's other metrics with it.
        $metric = function (string $key, string $label, callable $value, ?string $unit) {
            try {
                return ['key' => $key, 'label' => $label, 'value' => $value(), 'unit' => $unit];
            } catch (\Throwable $e) {
                return null;
            }
        };

        $community = ['exposure_invite_gate' => 'Invite-only gate'];
        if ($production) {
            $community['events_upcoming_published'] = 'Upcoming published events';
        }

        return [
            'weather' => [
                'keys' => [
                    'weather_freshness_live_conditions' => 'Live conditions data',
                    'weather_live_cities_stale' => 'Live conditions by city',
                    'weather_freshness_daily_weather' => 'Daily weather data',
                    'weather_freshness_air_quality' => 'Air quality data',
                    'weather_freshness_climate_snapshot' => 'Climate snapshot data',
                    'weather_completeness' => 'Live data completeness',
                    'job_earthquakes' => 'Earthquake feed',
                    'job_groundwater' => 'Groundwater feed',
                    'job_snow' => 'Snow feed',
                    'job_drought' => 'Drought feed',
                ],
                'checks' => fn () => [
                    $weather('weather_freshness_live_conditions', 'Live conditions data', fn () => $mrv->liveConditions()),
                    $weather('weather_live_cities_stale', 'Live conditions by city', fn () => $mrv->liveCitiesStale()),
                    $weather('weather_freshness_daily_weather', 'Daily weather data', fn () => $mrv->dailyWeather()),
                    $weather('weather_freshness_air_quality', 'Air quality data', fn () => $mrv->airQuality()),
                    $weather('weather_freshness_climate_snapshot', 'Climate snapshot data', fn () => $mrv->climateSnapshot()),
                    $weather('weather_completeness', 'Live data completeness', fn () => $mrv->weatherCompleteness()),
                    $this->jobCheck('earthquakes', 'Earthquake feed'),
                    $this->jobCheck('groundwater', 'Groundwater feed'),
                    $this->jobCheck('snow', 'Snow feed'),
                    $this->jobCheck('drought', 'Drought feed'),
                ],
                'metrics' => fn () => array_values(array_filter([
                    $metric('open_meteo_calls_today', 'Open-Meteo calls today (tracked jobs)', fn () => $mrv->openMeteoCallsToday(), 'calls'),
                ])),
            ],
            'ingestion' => [
                'keys' => [
                    'ingest_ics_age' => 'Calendar feed fetches',
                    'sources_failing' => 'Calendar sources failing',
                ],
                'checks' => fn () => [
                    $guard('ingest_ics_age', 'Calendar feed fetches', fn () => $mrv->ingestIcsAge()),
                    $guard('sources_failing', 'Calendar sources failing', fn () => $mrv->sourcesFailing()),
                ],
                'metrics' => fn () => array_values(array_filter([
                    $metric('sources_deactivated', 'Ingestion sources switched off', fn () => $mrv->sourcesDeactivated(), 'count'),
                    $metric('ingest_tribe_events_age', 'Hours since the last Tribe Events fetch', fn () => $mrv->collectorAge('tribe_events'), 'hours'),
                    $metric('ingest_squarespace_events_age', 'Hours since the last Squarespace fetch', fn () => $mrv->collectorAge('squarespace_events'), 'hours'),
                ])),
            ],
            'community' => [
                'keys' => $community,
                'checks' => fn () => array_values(array_filter([
                    $guard('exposure_invite_gate', 'Invite-only gate', fn () => $mrv->inviteGate($environment)),
                    $production ? $guard('events_upcoming_published', 'Upcoming published events', fn () => $mrv->eventsUpcomingPublished()) : null,
                ])),
                'metrics' => fn () => array_values(array_filter([
                    $metric('moderation_backlog', 'Events awaiting moderation', fn () => $mrv->moderationBacklog(), 'events'),
                    $metric('moderation_oldest_age', 'Oldest event awaiting moderation', fn () => $mrv->moderationOldestAgeDays(), 'days'),
                    $metric('submissions_week', 'Public submissions in the last 7 days', fn () => $mrv->submissionsWeek(), 'count'),
                    $metric('spam_rate_week', 'Share of last 7 days\' submissions marked spam', fn () => $mrv->spamRateWeek(), 'percent'),
                    $metric('channels_active', 'Active channels', fn () => $mrv->channelsActive(), 'count'),
                    $metric('curators_active', 'Curators with an active channel', fn () => $mrv->curatorsActive(), 'count'),
                    // On production this is a check (above); elsewhere a count, because staging holds unpublished candidates.
                    $production ? null : $metric('events_upcoming_published', 'Upcoming published events', fn () => $mrv->upcomingPublishedCount(), 'count'),
                ])),
            ],
            'mail' => [
                'keys' => ['mail_failures' => 'Mail send failures'],
                'checks' => fn () => [
                    $this->cached('mail_failures', 300, fn () => $mrv->guard('mail_failures', 'Mail send failures', fn () => $mrv->mailFailures()), 900),
                ],
                'metrics' => fn () => [],
            ],
            'data' => [
                'keys' => ['data_compression_enabled' => 'Data endpoint compression'],
                'checks' => fn () => [
                    $guard('data_compression_enabled', 'Data endpoint compression', fn () => $mrv->dataCompression()),
                ],
                'metrics' => fn () => [],
            ],
        ];
    }
}
