<?php

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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference_id')->unique(); // Ref internal bridge
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_ref_id'); // Ref dari web client
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 15, 2); // Nominal asli
            $table->decimal('fee', 15, 2); // Total admin fee
            $table->decimal('total_amount', 15, 2); // Nominal + Fee

            $table->string('pg_ref_id')->nullable(); // ID dari PG (Midtrans/dll)
            $table->json('pg_response')->nullable(); // Raw response awal
            $table->string('checkout_url')->nullable(); // URL bayar dari response payment gateway
            $table->string('qris_url')->nullable(); // URL gambar qris bayar dari response payment gateway jika channel yang dipilih qris
            $table->string('payment_code')->nullable(); // String QRIS atau No Virtual Account
            $table->string('redirect_url', 500)->nullable(); // Redirect URL merchant (dinamis)

            $table->enum('status', ['PENDING', 'PAID', 'DONE', 'FAILED', 'EXPIRED', 'REFUNDED'])->default('PENDING');
            $table->string('pg_status')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at');
            $table->timestamps();

            $table->index('status');

            // Mencegah merchant mengirim ID yang sama berkali-kali
            $table->unique(['merchant_id', 'merchant_ref_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
