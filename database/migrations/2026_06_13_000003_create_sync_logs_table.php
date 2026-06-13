<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('action', ['scrape', 'create', 'update', 'remove', 'error']);
            $table->enum('status', ['success', 'failure']);
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['dealer_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
