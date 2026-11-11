<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            throw new RuntimeException('Cannot protect the wallet ledger: wallet_transactions table is missing.');
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(
                "CREATE TRIGGER IF NOT EXISTS wallet_transactions_immutable_update "
                ."BEFORE UPDATE ON wallet_transactions FOR EACH ROW "
                ."BEGIN SELECT RAISE(ABORT, 'wallet_transaction_immutable'); END"
            );
            DB::unprepared(
                "CREATE TRIGGER IF NOT EXISTS wallet_transactions_immutable_delete "
                ."BEFORE DELETE ON wallet_transactions FOR EACH ROW "
                ."BEGIN SELECT RAISE(ABORT, 'wallet_transaction_immutable'); END"
            );

            return;
        }

        if ($driver === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_immutable_delete');
            DB::unprepared(
                "CREATE TRIGGER wallet_transactions_immutable_update "
                ."BEFORE UPDATE ON wallet_transactions FOR EACH ROW "
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet_transaction_immutable'"
            );
            DB::unprepared(
                "CREATE TRIGGER wallet_transactions_immutable_delete "
                ."BEFORE DELETE ON wallet_transactions FOR EACH ROW "
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet_transaction_immutable'"
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared(
                "CREATE OR REPLACE FUNCTION farast_reject_wallet_transaction_mutation() "
                ."RETURNS trigger LANGUAGE plpgsql AS $$ "
                ."BEGIN RAISE EXCEPTION 'wallet_transaction_immutable'; END; $$"
            );
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_immutable_update ON wallet_transactions');
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_immutable_delete ON wallet_transactions');
            DB::unprepared(
                "CREATE TRIGGER wallet_transactions_immutable_update "
                ."BEFORE UPDATE ON wallet_transactions FOR EACH ROW "
                ."EXECUTE FUNCTION farast_reject_wallet_transaction_mutation()"
            );
            DB::unprepared(
                "CREATE TRIGGER wallet_transactions_immutable_delete "
                ."BEFORE DELETE ON wallet_transactions FOR EACH ROW "
                ."EXECUTE FUNCTION farast_reject_wallet_transaction_mutation()"
            );

            return;
        }

        throw new RuntimeException("Wallet ledger immutability is not implemented for database driver [{$driver}].");
    }

    public function down(): void
    {
        // Intentionally retain immutable-ledger protections during rollback.
    }
};
