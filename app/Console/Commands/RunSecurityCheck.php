<?php

namespace App\Console\Commands;

use App\Support\Security\SecurityScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The same scan as System > Security Check, without the page: walks every uploaded file
 * (threats are moved to quarantine, where an admin can restore or delete them on that page),
 * then runs the health checks. Scheduled every six hours (routes/console.php); can also be
 * run by hand: php artisan security:check
 *
 * The result goes to the log (storage/logs/laravel.log): a warning when something was
 * quarantined or a check failed, otherwise one info line.
 */
class RunSecurityCheck extends Command
{
    protected $signature = 'security:check';

    protected $description = 'Scan uploaded files (quarantine threats) and run the health checks, like System > Security Check';

    public function handle(SecurityScanner $scanner): int
    {
        // files: same batches as the page, one after another until done
        $scan = $scanner->startFileScan();
        $threats = [];
        $offset = 0;
        do {
            $batch = $scanner->scanBatch($scan['token'], $offset);
            array_push($threats, ...$batch['findings']);
            $offset = $batch['next_offset'];
        } while (!$batch['done']);

        $this->info("Files scanned: {$scan['total']}. Quarantined: " . count($threats) . '.');
        foreach ($threats as $threat) {
            $this->warn("  quarantined {$threat['path']}: {$threat['reason']}");
        }

        // health checks: anything not "ok" is reported
        $problems = [];
        foreach (SecurityScanner::HEALTH as $health) {
            $result = $scanner->check($health['id']);
            foreach ($result['items'] as $item) {
                if ($item['status'] !== 'ok') {
                    $problems[] = ['check' => $result['title'], ...$item];
                    $this->warn("  {$item['status']}: {$result['title']} > {$item['label']}: {$item['detail']}");
                }
            }
        }
        $failed = array_filter($problems, fn(array $p) => $p['status'] === 'fail');
        $this->info('Health checks: ' . (count($problems) === 0 ? 'all OK.' : count($failed) . ' failed, ' . (count($problems) - count($failed)) . ' warnings.'));

        $context = ['files_scanned' => $scan['total'], 'quarantined' => $threats, 'problems' => $problems];
        if ($threats !== [] || $failed !== []) {
            Log::channel('app_file')->info('Security check found problems (see System > Security Check).', $context);
        } else {
            Log::channel('app_file')->info('Security check: no threats found.', ['files_scanned' => $scan['total'], 'warnings' => count($problems)]);
        }

        return self::SUCCESS;
    }
}
