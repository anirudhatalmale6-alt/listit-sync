<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('website_url')->unique();
            $table->string('platform_type')->nullable(); // autowebdesign, wordpress, cogcms, bolt, earthstorm, custom, unknown
            $table->enum('jurisdiction', ['ie', 'im']); // Ireland or Isle of Man
            $table->string('listit_dealer_id')->nullable(); // ID on listit.ie or listit.im
            $table->string('location')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->enum('tier', ['free', 'paid'])->default('free');
            $table->boolean('active')->default(true);
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->integer('scrape_failures')->default(0);
            $table->text('scrape_error')->nullable();
            $table->json('config')->nullable(); // per-dealer overrides
            $table->timestamps();

            $table->index('jurisdiction');
            $table->index('active');
            $table->index('platform_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealers');
    }
};
