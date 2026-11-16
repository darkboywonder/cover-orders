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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('recharge_subscription_id');
            $table->string('recharge_customer_id')->nullable();
            $table->string('recharge_address_id')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('origin_shopify_order_id')->nullable();
            $table->foreignId('origin_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->boolean('is_covered')->default(false);
            $table->bigInteger('covered_premium_cents')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('external_product_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('order_interval_unit', 16)->nullable();
            $table->unsignedInteger('order_interval_frequency')->nullable();
            $table->date('next_charge_scheduled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'recharge_subscription_id']);
            $table->index(['shop_id', 'origin_shopify_order_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->after('recharge_status')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
        });

        Schema::dropIfExists('subscriptions');
    }
};
