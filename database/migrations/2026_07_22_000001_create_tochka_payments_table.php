<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tochka_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->cascadeOnDelete();
            $table->foreignId('uon_binding_id')->constrained('uon_bindings')->cascadeOnDelete();
            $table->string('uon_request_id');
            $table->string('contract_number');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('RUB');
            $table->string('status')->default('pending');
            $table->string('payment_link_id')->unique();
            $table->string('operation_id')->nullable()->index();
            $table->text('payment_url')->nullable();
            $table->json('tochka_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('uon_payment_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tochka_payments');
    }
};
