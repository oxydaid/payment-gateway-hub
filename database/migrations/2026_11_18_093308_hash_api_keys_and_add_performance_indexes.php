<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->string('key_prefix', 24)->nullable()->after('name');
            $table->string('key_hash', 64)->nullable()->after('key_prefix');
        });

        DB::table('api_keys')->orderBy('id')->each(function (object $row): void {
            $plainKey = (string) $row->key;
            $prefix = substr($plainKey, 0, 16).'****';
            $hash = hash('sha256', $plainKey);

            DB::table('api_keys')
                ->where('id', $row->id)
                ->update([
                    'key_prefix' => $prefix,
                    'key_hash' => $hash,
                ]);
        });

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropUnique(['key']);
        });

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('key');
            $table->string('key_hash', 64)->nullable(false)->change();
            $table->unique('key_hash');
            $table->index(['key_hash', 'is_active'], 'api_keys_key_hash_is_active_index');
        });

        Schema::table('merchants', function (Blueprint $table): void {
            $table->index(['api_key', 'is_active'], 'merchants_api_key_is_active_index');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->index(['status', 'created_at'], 'transactions_status_created_at_index');
            $table->index(['merchant_id', 'created_at'], 'transactions_merchant_id_created_at_index');
        });

        Schema::table('webhook_logs', function (Blueprint $table): void {
            $table->index(['transaction_id', 'direction', 'created_at'], 'webhook_logs_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_logs', function (Blueprint $table): void {
            $table->dropIndex('webhook_logs_lookup_index');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_status_created_at_index');
            $table->dropIndex('transactions_merchant_id_created_at_index');
        });

        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropIndex('merchants_api_key_is_active_index');
        });

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropIndex('api_keys_key_hash_is_active_index');
            $table->dropUnique(['key_hash']);
        });

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn(['key_hash', 'key_prefix']);
            $table->string('key')->nullable()->unique()->after('name');
        });
    }
};
