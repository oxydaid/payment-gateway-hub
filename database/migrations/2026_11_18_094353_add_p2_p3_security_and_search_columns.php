<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('merchants', 'webhook_secret')) {
            Schema::table('merchants', function (Blueprint $table): void {
                $table->text('webhook_secret')->nullable()->after('api_key');
            });

            // Backfill existing merchants with encrypted webhook_secret derived from legacy api_key
            // to preserve backward compatibility for HMAC signature verification.
            DB::table('merchants')
                ->whereNotNull('api_key')
                ->whereNull('webhook_secret')
                ->orderBy('id')
                ->each(function (object $merchant): void {
                    DB::table('merchants')
                        ->where('id', $merchant->id)
                        ->update([
                            'webhook_secret' => Crypt::encryptString((string) $merchant->api_key),
                        ]);
                });
        }

        if (! Schema::hasColumn('transactions', 'idempotency_key')) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->string('idempotency_key', 128)->nullable()->after('merchant_ref_id');
                $table->unique(['merchant_id', 'idempotency_key'], 'transactions_merchant_idempotency_unique');
            });
        }

        // MariaDB uses native UUID type for reference_id (cannot be FULLTEXT indexed);
        // merchant_ref_id is VARCHAR(255) and supports InnoDB FULLTEXT search.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->fullText(['merchant_ref_id'], 'transactions_merchant_ref_fulltext');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->dropFullText('transactions_merchant_ref_fulltext');
            });
        }

        if (Schema::hasColumn('transactions', 'idempotency_key')) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->dropUnique('transactions_merchant_idempotency_unique');
                $table->dropColumn('idempotency_key');
            });
        }

        if (Schema::hasColumn('merchants', 'webhook_secret')) {
            Schema::table('merchants', function (Blueprint $table): void {
                $table->dropColumn('webhook_secret');
            });
        }
    }
};
