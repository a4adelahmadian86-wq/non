<?php

namespace App\Services;

use App\Models\FarastCharge;
use App\Models\FarastRefund;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletLedgerReconciliationService
{
    public function reconcile(?int $walletId = null, ?int $userId = null): array
    {
        $walletIds = Wallet::query()
            ->when($walletId !== null, fn ($query) => $query->whereKey($walletId))
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->pluck('id');

        $results = [];
        foreach ($walletIds as $id) {
            $results[] = DB::transaction(fn (): array => $this->inspectWallet((int) $id));
        }

        $summary = [
            'wallets_checked' => count($results),
            'balanced' => count(array_filter($results, fn (array $row): bool => $row['status'] === 'balanced')),
            'needs_review' => count(array_filter($results, fn (array $row): bool => $row['status'] === 'needs_review')),
            'mismatched' => count(array_filter($results, fn (array $row): bool => $row['status'] === 'mismatch')),
            'transactions_checked' => array_sum(array_column($results, 'transactions_count')),
        ];

        return ['summary' => $summary, 'wallets' => $results];
    }

    private function inspectWallet(int $walletId): array
    {
        $wallet = Wallet::query()->whereKey($walletId)->firstOrFail();
        $state = [
            'transactions_count' => 0,
            'opening_balance_rials' => null,
            'last_ledger_balance_rials' => null,
            'issues' => [],
        ];

        $wallet->transactions()->orderBy('id')->chunkById(500, function ($batch) use (&$state, $wallet): void {
            $references = $this->loadReferences($batch, (int) $wallet->user_id);

            foreach ($batch as $transaction) {
                $state['transactions_count']++;
                $before = (int) $transaction->balance_before;
                $after = (int) $transaction->balance_after;
                $amount = (int) $transaction->amount_rials;

                if ($state['opening_balance_rials'] === null) {
                    $state['opening_balance_rials'] = $before;
                    if ($before > 0) {
                        $this->issue($state, 'warning', 'opening_balance_unverified', null);
                    }
                }

                if ($state['last_ledger_balance_rials'] !== null && $before !== $state['last_ledger_balance_rials']) {
                    $this->issue($state, 'error', 'balance_chain_break', (int) $transaction->id);
                }

                if ($amount <= 0) {
                    $this->issue($state, 'error', 'non_positive_transaction_amount', (int) $transaction->id);
                }

                $expectedAfter = match ($transaction->type) {
                    'credit' => $before + $amount,
                    'debit' => $before - $amount,
                    default => null,
                };

                if ($expectedAfter === null) {
                    $this->issue($state, 'error', 'unsupported_transaction_type', (int) $transaction->id);
                } elseif ($amount > 0 && $after !== $expectedAfter) {
                    $this->issue($state, 'error', 'transaction_arithmetic_mismatch', (int) $transaction->id);
                }

                if (blank($transaction->idempotency_key)) {
                    $this->issue($state, 'error', 'missing_idempotency_key', (int) $transaction->id);
                }

                if (! $this->referenceMatches($transaction, $references)) {
                    $this->issue($state, 'error', 'reference_not_reconciled', (int) $transaction->id);
                }

                $state['last_ledger_balance_rials'] = $after;
            }
        });

        $currentBalance = (int) $wallet->balance_rials;
        if ($state['transactions_count'] === 0) {
            if ($currentBalance > 0) {
                $state['opening_balance_rials'] = $currentBalance;
                $this->issue($state, 'warning', 'opening_balance_unverified', null);
            } else {
                $state['opening_balance_rials'] = 0;
                $state['last_ledger_balance_rials'] = 0;
            }
        } elseif ($state['last_ledger_balance_rials'] !== $currentBalance) {
            $this->issue($state, 'error', 'wallet_balance_differs_from_ledger', null);
        }

        $hasErrors = collect($state['issues'])->contains(fn (array $issue): bool => $issue['severity'] === 'error');
        $hasWarnings = collect($state['issues'])->contains(fn (array $issue): bool => $issue['severity'] === 'warning');

        return [
            'wallet_id' => (int) $wallet->id,
            'user_id' => (int) $wallet->user_id,
            'balance_rials' => $currentBalance,
            'transactions_count' => (int) $state['transactions_count'],
            'opening_balance_rials' => (int) ($state['opening_balance_rials'] ?? 0),
            'last_ledger_balance_rials' => $state['last_ledger_balance_rials'],
            'status' => $hasErrors ? 'mismatch' : ($hasWarnings ? 'needs_review' : 'balanced'),
            'issues' => $state['issues'],
        ];
    }

    private function loadReferences($transactions, int $userId): array
    {
        $orderIds = $transactions
            ->filter(fn (WalletTransaction $transaction): bool => in_array($transaction->reference_type, ['order', 'store_order', 'typing_deposit', 'wallet_topup'], true))
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        $chargeIds = $transactions
            ->filter(fn (WalletTransaction $transaction): bool => in_array($transaction->reference_type, ['farast_charge', 'farast_postpaid_charge'], true))
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        $refundIds = $transactions
            ->where('reference_type', 'farast_refund')
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        return [
            'orders' => $orderIds->isEmpty() ? collect() : Order::query()->whereIn('id', $orderIds)->where('user_id', $userId)->get()->keyBy('id'),
            'payments' => $orderIds->isEmpty() ? collect() : Payment::query()
                ->whereIn('order_id', $orderIds)
                ->where('status', 'paid')
                ->whereNotNull('paid_at')
                ->get()
                ->groupBy('order_id'),
            'charges' => $chargeIds->isEmpty() ? collect() : FarastCharge::query()->whereIn('id', $chargeIds)->where('actor_id', $userId)->get()->keyBy('id'),
            'refunds' => $refundIds->isEmpty() ? collect() : FarastRefund::query()->whereIn('id', $refundIds)->where('actor_id', $userId)->get()->keyBy('id'),
        ];
    }

    private function referenceMatches(WalletTransaction $transaction, array $references): bool
    {
        if (! $transaction->reference_type || ! $transaction->reference_id) {
            return false;
        }

        $referenceId = (int) $transaction->reference_id;

        if (in_array($transaction->reference_type, ['order', 'store_order', 'typing_deposit', 'wallet_topup'], true)) {
            $order = $references['orders']->get($referenceId);
            if (! $order) {
                return false;
            }

            $orderStateIsValid = $transaction->reference_type === 'typing_deposit'
                ? $order->status === 'deposit_paid' && $order->paid_at !== null
                : $order->isPaid();

            if (! $orderStateIsValid || (int) $order->total_rials !== (int) $transaction->amount_rials) {
                return false;
            }

            if ($transaction->reference_type === 'wallet_topup' && data_get($order->pricing_snapshot, 'kind') !== 'wallet_topup') {
                return false;
            }

            $expectedGateway = $transaction->reference_type === 'wallet_topup' ? 'zarinpal' : 'wallet';

            return $references['payments']->get($referenceId, collect())->contains(
                fn (Payment $payment): bool =>
                    $payment->gateway === $expectedGateway
                    && (int) $payment->amount_rials === (int) $transaction->amount_rials
            );
        }

        if (in_array($transaction->reference_type, ['farast_charge', 'farast_postpaid_charge'], true)) {
            $charge = $references['charges']->get($referenceId);

            return $charge !== null
                && $charge->status === 'charged'
                && (int) $charge->total === (int) $transaction->amount_rials;
        }

        if ($transaction->reference_type === 'farast_refund') {
            $refund = $references['refunds']->get($referenceId);

            return $refund !== null
                && $refund->status === 'completed'
                && (int) $refund->amount === (int) $transaction->amount_rials;
        }

        return false;
    }

    private function issue(array &$state, string $severity, string $code, ?int $transactionId): void
    {
        $state['issues'][] = [
            'severity' => $severity,
            'code' => $code,
            'transaction_id' => $transactionId,
        ];
    }
}
