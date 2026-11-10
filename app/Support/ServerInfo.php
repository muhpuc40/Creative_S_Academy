<?php

namespace App\Support;

use App\Models\SmsSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Collects information about the machine that runs the app.
 * Works on Windows (XAMPP) and Linux. Every block is wrapped so one failure never breaks the page.
 */
class ServerInfo
{
    public static function bytes(?float $bytes, int $precision = 1): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $precision).' '.$units[$i];
    }

    public static function duration(?int $seconds): string
    {
        if ($seconds === null || $seconds < 0) {
            return '—';
        }
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);

        return trim(($d ? "{$d}d " : '').($h ? "{$h}h " : '')."{$m}m");
    }

    /** Everything the /server page needs. */
    public function all(): array
    {
        $sys = $this->system();

        return [
            'sys' => $sys,
            'server' => $this->server($sys),
            'cpu' => $this->cpu($sys),
            'memory' => $this->memory($sys),
            'disks' => $this->disks(),
            'php' => $this->php(),
            'extensions' => $this->extensions(),
            'database' => $this->database(),
            'laravel' => $this->laravel(),
        ];
    }

    // ------------------------------------------------------------------ system (OS, CPU, RAM, uptime, processes)
    private function system(): array
    {
        $data = [
            'os' => PHP_OS_FAMILY,
            'os_version' => php_uname('r'),
            'cpu_name' => null, 'cores' => null, 'threads' => null, 'mhz' => null,
            'load_pct' => null, 'load_avg' => null,
            'mem_total' => null, 'mem_free' => null,
            'boot' => null, 'processes' => null,
        ];

        try {
            $extra = PHP_OS_FAMILY === 'Windows' ? $this->windows() : $this->linux();
            $data = array_merge($data, array_filter($extra, fn ($v) => $v !== null));
        } catch (Throwable $e) {
            report($e);
        }

        return $data;
    }

    private function windows(): array
    {
        $script = <<<'PS'
$os = Get-CimInstance Win32_OperatingSystem
$cpus = @(Get-CimInstance Win32_Processor)
$cores = ($cpus | Measure-Object NumberOfCores -Sum).Sum
$threads = ($cpus | Measure-Object NumberOfLogicalProcessors -Sum).Sum
$load = ($cpus | Measure-Object LoadPercentage -Average).Average
[pscustomobject]@{
  os = $os.Caption; version = $os.Version
  total_kb = $os.TotalVisibleMemorySize; free_kb = $os.FreePhysicalMemory
  boot = ([DateTimeOffset]$os.LastBootUpTime).ToUnixTimeSeconds()
  cpu = $cpus[0].Name.Trim(); cores = $cores; threads = $threads
  mhz = $cpus[0].MaxClockSpeed; load = $load
  procs = @(Get-Process).Count
} | ConvertTo-Json -Compress
PS;

        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $out = $this->run('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand '.$encoded.' 2>NUL');
        $j = json_decode((string) $out, true);

        if (! is_array($j)) {
            return [];
        }

        return [
            'os' => $j['os'] ?? null,
            'os_version' => $j['version'] ?? null,
            'cpu_name' => $j['cpu'] ?? null,
            'cores' => $j['cores'] ?? null,
            'threads' => $j['threads'] ?? null,
            'mhz' => $j['mhz'] ?? null,
            'load_pct' => isset($j['load']) ? (float) $j['load'] : null,
            'mem_total' => isset($j['total_kb']) ? (float) $j['total_kb'] * 1024 : null,
            'mem_free' => isset($j['free_kb']) ? (float) $j['free_kb'] * 1024 : null,
            'boot' => $j['boot'] ?? null,
            'processes' => $j['procs'] ?? null,
        ];
    }

    private function linux(): array
    {
        $out = [];

        if (is_readable('/etc/os-release') && preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', (string) file_get_contents('/etc/os-release'), $m)) {
            $out['os'] = $m[1];
        }

        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = (string) file_get_contents('/proc/cpuinfo');
            $out['threads'] = preg_match_all('/^processor\s*:/m', $cpuinfo) ?: null;
            if (preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $m)) {
                $out['cpu_name'] = trim($m[1]);
            }
            if (preg_match('/^cpu MHz\s*:\s*([\d.]+)/m', $cpuinfo, $m)) {
                $out['mhz'] = (int) round((float) $m[1]);
            }
            preg_match_all('/^physical id\s*:\s*(\d+)/m', $cpuinfo, $sockets);
            if (preg_match('/^cpu cores\s*:\s*(\d+)/m', $cpuinfo, $m)) {
                $out['cores'] = (int) $m[1] * max(1, count(array_unique($sockets[1] ?? [])));
            }
        }

        if (function_exists('sys_getloadavg') && ($avg = sys_getloadavg())) {
            $out['load_avg'] = array_map(fn ($v) => round($v, 2), $avg);
            $threads = $out['threads'] ?? 1;
            $out['load_pct'] = min(100, round($avg[0] / max(1, $threads) * 100, 1));
        }

        if (is_readable('/proc/meminfo')) {
            $mem = (string) file_get_contents('/proc/meminfo');
            if (preg_match('/^MemTotal:\s+(\d+)/m', $mem, $t)) {
                $out['mem_total'] = (float) $t[1] * 1024;
            }
            if (preg_match('/^MemAvailable:\s+(\d+)/m', $mem, $f)) {
                $out['mem_free'] = (float) $f[1] * 1024;
            }
        }

        if (is_readable('/proc/uptime')) {
            $out['boot'] = time() - (int) explode(' ', (string) file_get_contents('/proc/uptime'))[0];
        }

        $out['processes'] = count(glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: []) ?: null;

        return $out;
    }

    // ------------------------------------------------------------------ blocks for the page
    private function server(array $sys): array
    {
        $uptime = $sys['boot'] ? time() - (int) $sys['boot'] : null;
        $host = gethostname() ?: '—';

        return [
            'Hostname' => $host,
            'Operating system' => trim(($sys['os'] ?? '').' '.($sys['os_version'] ?? '')),
            'Architecture' => php_uname('m'),
            'Web server' => $_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI,
            'Server IP' => $_SERVER['SERVER_ADDR'] ?? @gethostbyname($host),
            'Server port' => $_SERVER['SERVER_PORT'] ?? null,
            'HTTPS' => request()->isSecure(),
            'Your IP' => request()->ip(),
            'Document root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
            'Project path' => base_path(),
            'Server time' => now()->format('d M Y, h:i:s A'),
            'Timezone' => date_default_timezone_get().' (UTC'.date('P').')',
            'Booted at' => $sys['boot'] ? date('d M Y, h:i A', (int) $sys['boot']) : null,
            'Uptime' => self::duration($uptime),
            'Running processes' => $sys['processes'],
        ];
    }

    private function cpu(array $sys): array
    {
        return [
            'Processor' => $sys['cpu_name'],
            'Physical cores' => $sys['cores'],
            'Logical threads' => $sys['threads'],
            'Clock speed' => $sys['mhz'] ? number_format($sys['mhz']).' MHz ('.round($sys['mhz'] / 1000, 2).' GHz)' : null,
            'Current load' => $sys['load_pct'] !== null ? $sys['load_pct'].' %' : null,
            'Load average (1 / 5 / 15 min)' => $sys['load_avg'] ? implode('  /  ', $sys['load_avg']) : (PHP_OS_FAMILY === 'Windows' ? 'Not available on Windows' : null),
        ];
    }

    private function memory(array $sys): array
    {
        $total = $sys['mem_total'];
        $free = $sys['mem_free'];
        $used = ($total !== null && $free !== null) ? $total - $free : null;

        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'percent' => ($total && $used !== null) ? round($used / $total * 100, 1) : null,
            'php_limit' => ini_get('memory_limit'),
            'php_now' => memory_get_usage(true),
            'php_peak' => memory_get_peak_usage(true),
        ];
    }

    private function disks(): array
    {
        $paths = [];

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (range('A', 'Z') as $letter) {
                if (@is_dir($letter.':\\')) {
                    $paths[] = $letter.':\\';
                }
            }
        } else {
            $paths = array_unique(['/', base_path()]);
        }

        $project = strtoupper(substr(base_path(), 0, 1));
        $disks = [];
        $seen = [];

        foreach ($paths as $path) {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (! $total) {
                continue;
            }
            $key = $total.'-'.$free;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $used = $total - $free;
            $disks[] = [
                'path' => $path,
                'total' => $total,
                'free' => $free,
                'used' => $used,
                'percent' => round($used / $total * 100, 1),
                'project' => PHP_OS_FAMILY === 'Windows' ? strtoupper($path[0]) === $project : str_starts_with(base_path(), $path),
            ];
        }

        return $disks;
    }

    private function php(): array
    {
        $opcache = function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
        $disabled = trim((string) ini_get('disable_functions'));

        return [
            'PHP version' => PHP_VERSION,
            'SAPI' => PHP_SAPI,
            'Zend engine' => zend_version(),
            'php.ini' => php_ini_loaded_file() ?: 'none',
            'Memory limit' => ini_get('memory_limit'),
            'Max execution time' => ini_get('max_execution_time').' s',
            'Upload max filesize' => ini_get('upload_max_filesize'),
            'Post max size' => ini_get('post_max_size'),
            'Max input vars' => ini_get('max_input_vars'),
            'OPcache enabled' => $opcache,
            'Display errors' => filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN),
            'Disabled functions' => $disabled !== '' ? $disabled : 'none',
        ];
    }

    private function extensions(): array
    {
        $list = get_loaded_extensions();
        natcasesort($list);

        return array_values($list);
    }

    private function database(): array
    {
        $result = ['ok' => false, 'latency' => null, 'error' => null, 'items' => []];

        try {
            $conn = DB::connection();
            $config = $conn->getConfig();
            $driver = $conn->getDriverName();

            $start = microtime(true);
            $conn->select('select 1');
            $result['latency'] = round((microtime(true) - $start) * 1000, 2);
            $result['ok'] = true;

            $items = [
                'Connection' => $conn->getName(),
                'Driver' => $driver,
                'Host' => ($config['host'] ?? '—').(isset($config['port']) ? ':'.$config['port'] : ''),
                'Database' => $conn->getDatabaseName(),
                'Response time' => $result['latency'].' ms',
            ];

            if ($driver === 'sqlite') {
                $items['Version'] = $conn->selectOne('select sqlite_version() as v')->v ?? null;
                $file = $config['database'] ?? null;
                $items['File size'] = ($file && is_file($file)) ? self::bytes((float) filesize($file)) : null;
            } else {
                $items['Version'] = $conn->selectOne('select version() as v')->v ?? null;
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $name = $conn->getDatabaseName();
                $size = $conn->selectOne(
                    'select coalesce(sum(data_length + index_length), 0) as s, count(*) as t from information_schema.tables where table_schema = ?',
                    [$name]
                );
                $items['Database size'] = self::bytes((float) $size->s);
                $items['Tables'] = (int) $size->t;
                $items['Server time (DB)'] = $conn->selectOne('select now() as n')->n ?? null;
                $items['DB timezone'] = $conn->selectOne('select @@session.time_zone as z')->z ?? null;

                $status = collect($conn->select("show global status where Variable_name in ('Threads_connected','Threads_running','Max_used_connections','Uptime','Questions','Slow_queries','Aborted_connects')"))
                    ->pluck('Value', 'Variable_name');
                $max = $conn->selectOne("show variables like 'max_connections'")->Value ?? null;

                $items['Connections (now / max)'] = ($status['Threads_connected'] ?? '—').' / '.($max ?? '—');
                $items['Running queries'] = $status['Threads_running'] ?? null;
                $items['Peak connections'] = $status['Max_used_connections'] ?? null;
                $items['Total queries'] = isset($status['Questions']) ? number_format((int) $status['Questions']) : null;
                $items['Slow queries'] = $status['Slow_queries'] ?? null;
                $items['Failed connects'] = $status['Aborted_connects'] ?? null;
                $items['DB uptime'] = isset($status['Uptime']) ? self::duration((int) $status['Uptime']) : null;
            }

            $result['items'] = $items;
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private function laravel(): array
    {
        $log = storage_path('logs/laravel.log');
        $jobs = $failed = null;

        try {
            $jobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        } catch (Throwable) {
        }

        $cacheOk = false;
        try {
            Cache::put('server-check', 'ok', 5);
            $cacheOk = Cache::get('server-check') === 'ok';
        } catch (Throwable) {
        }

        return [
            'Laravel version' => app()->version(),
            'Environment' => app()->environment(),
            'Debug mode' => (bool) config('app.debug'),
            'App URL' => config('app.url'),
            'App timezone' => config('app.timezone'),
            'Locale' => config('app.locale'),
            'Maintenance mode' => app()->isDownForMaintenance(),
            'Config cached' => app()->configurationIsCached(),
            'Routes cached' => app()->routesAreCached(),
            'Cache driver' => config('cache.default').($cacheOk ? ' (working)' : ' (failed)'),
            'Session driver' => config('session.driver'),
            'Queue driver' => config('queue.default'),
            'Mail driver' => config('mail.default'),
            'SMS driver' => SmsSetting::current()->driver,
            'Pending jobs' => $jobs,
            'Failed jobs' => $failed,
            'storage/ writable' => is_writable(storage_path()),
            'bootstrap/cache writable' => is_writable(base_path('bootstrap/cache')),
            'Log file size' => is_file($log) ? self::bytes((float) filesize($log)) : 'no log yet',
        ];
    }

    // ------------------------------------------------------------------ helpers
    private function run(string $command): ?string
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (! function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
            return null;
        }

        $out = @shell_exec($command);

        return is_string($out) ? $out : null;
    }
}
