<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderExportOrderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_export_order', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('order_export_id');
            $table->unsignedBigInteger('order_id');

            $table->timestamps();

            $table->foreign('order_export_id')
                ->references('id')
                ->on('order_exports')
                ->onDelete('cascade');

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->onDelete('cascade');

            $table->unique(
                ['order_export_id', 'order_id'],
                'export_order_unique'
            );
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_export_order');
    }
}
