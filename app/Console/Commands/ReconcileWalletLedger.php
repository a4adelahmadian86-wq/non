<?php

namespace App\\Console\\Commands;

use App\\Services\\WalletLedgerReconciliationService;
use Illuminate\\Console\\Command;
use Illuminate\\Support\\Facades\\Log;
use Throwable;

class ReconcileWalletLedger extends Command
{
    protected $signature = 'wallet:ledger:reconcile {--wallet= : Inspect one wallet ID} {--user= : Inspect wallets for one user ID}';

    protected $description = 'Read-only reconciliation of wallet balances, immutable ledger chains and financial references';

    public function handle(WalletLedgerReconciliationService $reconciliation): int
    {
        $walletId = $this->positiveOption('wallet');
        $userId = $this->positiveOption('user');

        if ($this->option('wallet') !== null && $walletId === null) {
            $this->error('The --wallet option must be a positive integer.');

            return self::INVALID;
        }

        if ($this->option('user') !== null && $userId === null) {
            $this->error('The --user option must be a positive integer.');

            return self::INVALID;
        }

        try {
            $result = $reconciliation->reconcile($walletId, $userId);
        } catch (Throwable $exception) {
            Log::critical('farast.wallet.ledger_reconciliation_failed', [
                'exception_class' => get_class($exception),
                'wallet_id' => $walletId,
                'user_id' => $userId,
            ]);
            $this->error('Wallet ledger reconciliation could not complete. Inspect protected application logs.');

            return self::FAILURE;
        }

        $rows = array_map(fn (array $wallet): array => [
            $wallet['wallet_id'],
            $wallet['user_id'],
            $wallet['balance_rials'],
            $wallet['transactions_count'],
            $wallet['status'],
            implode(', ', array_unique(array_column($wallet['issues'], 'code'))),
        ], $result['wallets']);

        $this->table(['Wallet', 'User', 'Balance (IRR)', 'Entries', 'Status', 'Issues'], $rows);

        $summary = $result['summary'];
        $this->line(sprintf(
            'Checked %d wallet(s), %d transaction(s): %d balanced, %d need opening-balance review, %d mismatched.',
            $summary['wallets_checked'],
            $summary['transactions_checked'],
            $summary['balanced'],
            $summary['needs_review'],
            $summary['mismatched'],
        ));

        if ($summary['needs_review'] > 0 || $summary['mismatched'] > 0) {
            Log::warning('farast.wallet.ledger_reconciliation_attention_required', $summary);
            $this->warn('No balances were changed. Review opening-balance evidence or investigate mismatched ledger entries.');

            return self::FAILURE;
        }

        Log::info('farast.wallet.ledger_reconciliation_passed', $summary);
        $this->info('All inspected wallet balances reconcile with their ledger and source references.');

        return self::SUCCESS;
    }

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return $validated !== false && $validated > 0 ? $validated : null;
    }
}
