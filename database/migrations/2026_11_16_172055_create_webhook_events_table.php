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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('topic', 64);
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dedup_key', 191);
            $table->string('external_event_id')->nullable();
            $table->longText('payload');
            $table->json('headers')->nullable();
            $table->string('status', 16)->default('received');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('processing_ms')->nullable();
            $table->timestamps();

            $table->unique(['source', 'dedup_key']);
            $table->index(['status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
