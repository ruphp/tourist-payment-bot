<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uon_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_user_id')->unique()->constrained('telegram_users')->cascadeOnDelete();
            $table->string('contract_number');
            $table->string('phone');
            $table->string('uon_request_id');
            $table->string('uon_client_id')->nullable();
            $table->json('last_request_snapshot')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uon_bindings');
    }
};
