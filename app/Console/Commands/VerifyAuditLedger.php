<?php

namespace App\Console\Commands;

use App\Services\AuditIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifyAuditLedger extends Command
{
    protected $signature = 'farast:audit-ledger:verify';

    protected $description = 'Verify the integrity of the existing FARAST append-only audit ledger';

    public function handle(AuditIntegrityService $integrity): int
    {
        try {
            $result = $integrity->verify();
        } catch (Throwable $exception) {
            Log::critical('farast.audit.integrity_check_failed', [
                'exception_class' => get_class($exception),
            ]);
            $this->error('Audit ledger verification could not complete; inspect the protected application logs.');

            return self::FAILURE;
        }

        if (! ($result['ok'] ?? false)) {
            $errors = array_slice((array) ($result['errors'] ?? []), 0, 50);
            Log::critical('farast.audit.integrity_violation', [
                'checked' => (int) ($result['checked'] ?? 0),
                'error_count' => count((array) ($result['errors'] ?? [])),
                'affected_event_ids' => array_values(array_filter(array_column($errors, 'event_id'), 'is_string')),
            ]);
            $this->error('Audit ledger integrity verification failed. Review the protected logs and incident process.');

            return self::FAILURE;
        }

        Log::info('farast.audit.integrity_check_passed', [
            'checked' => (int) ($result['checked'] ?? 0),
        ]);
        $this->info('Audit ledger integrity verified: '.(int) ($result['checked'] ?? 0).' event(s).');

        return self::SUCCESS;
    }
}
