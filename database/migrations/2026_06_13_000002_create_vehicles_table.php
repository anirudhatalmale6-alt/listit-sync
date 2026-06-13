<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->string('source_url')->nullable();
            $table->string('source_id')->nullable(); // unique ID from dealer's site
            $table->string('listit_ad_id')->nullable(); // ID on Listit after push
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->integer('year')->nullable();
            $table->string('trim')->nullable();
            $table->string('body_type')->nullable();
            $table->string('fuel_type')->nullable();
            $table->string('transmission')->nullable();
            $table->string('engine_size')->nullable();
            $table->string('colour')->nullable();
            $table->integer('mileage')->nullable();
            $table->string('mileage_unit', 5)->default('km');
            $table->string('registration')->nullable();
            $table->string('vin')->nullable();
            $table->string('co2')->nullable();
            $table->date('nct_expiry')->nullable();
            $table->date('tax_expiry')->nullable();
            $table->integer('doors')->nullable();
            $table->integer('seats')->nullable();
            $table->json('images')->nullable(); // array of image URLs from source
            $table->json('listit_image_ids')->nullable(); // uploaded image IDs on Listit
            $table->boolean('has_photos')->default(false);
            $table->string('hash')->nullable(); // content hash for change detection
            $table->enum('sync_status', ['pending', 'synced', 'failed', 'removed'])->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['dealer_id', 'source_id']);
            $table->index('sync_status');
            $table->index('listit_ad_id');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
