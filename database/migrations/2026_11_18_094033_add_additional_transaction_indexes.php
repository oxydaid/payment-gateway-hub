<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->index('pg_ref_id', 'transactions_pg_ref_id_index');
            $table->index('created_at', 'transactions_created_at_index');
            $table->index(['merchant_id', 'status', 'created_at'], 'transactions_merchant_status_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_pg_ref_id_index');
            $table->dropIndex('transactions_created_at_index');
            $table->dropIndex('transactions_merchant_status_created_at_index');
        });
    }
};
