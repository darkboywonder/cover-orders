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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_order_id');
            $table->string('admin_graphql_api_id')->nullable();
            $table->string('name')->nullable();
            $table->char('currency', 3)->nullable();
            $table->bigInteger('total_price_cents')->default(0);
            $table->bigInteger('protection_price_cents')->default(0);
            $table->boolean('has_protection_line')->default(false);
            $table->string('origin', 32)->default('checkout');
            $table->string('recharge_order_id')->nullable();
            $table->string('recharge_status', 32)->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('recharge_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'shopify_order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
