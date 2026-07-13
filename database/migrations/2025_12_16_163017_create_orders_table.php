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

            $table->uuid('guid')->unique();              // partnerOrderId
            $table->string('comment')->nullable();
            $table->string('status')->index();           // new / updated / shipped / canceled
            $table->string('delivery_type')->nullable(); // DropShipping
            $table->integer('cash_on_delivery')->nullable();
            $table->string('delivery_address_id')->nullable();
            $table->string('delivery_company')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_street')->nullable();
            $table->string('delivery_phone')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('created_at_partner')->nullable();
            $table->timestamp('updated_at_partner')->nullable();

            $table->timestamps();
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
