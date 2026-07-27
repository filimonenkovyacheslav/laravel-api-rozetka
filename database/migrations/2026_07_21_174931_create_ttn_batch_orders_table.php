<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTtnBatchOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('ttn_batch_orders', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('ttn_batch_id');
            $table->unsignedBigInteger('order_id');

            $table
                ->string('status', 20)
                ->default('pending');

            $table
                ->string('tracking_number', 100)
                ->nullable();

            $table
                ->unsignedInteger('attempts')
                ->default(0);

            $table
                ->text('error_message')
                ->nullable();

            $table
                ->timestamp('processed_at')
                ->nullable();

            $table
                ->timestamp('last_attempt_at')
                ->nullable();

            $table->timestamps();

            $table->unique(
                [
                    'ttn_batch_id',
                    'order_id',
                ],
                'tbo_batch_order_unique'
            );

            $table->index(
                'order_id',
                'tbo_order_idx'
            );

            $table->index(
                'status',
                'tbo_status_idx'
            );

            $table
                ->foreign(
                    'ttn_batch_id',
                    'tbo_batch_fk'
                )
                ->references('id')
                ->on('ttn_batches')
                ->onDelete('cascade');

            $table
                ->foreign(
                    'order_id',
                    'tbo_order_fk'
                )
                ->references('id')
                ->on('orders')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ttn_batch_orders');
    }
}