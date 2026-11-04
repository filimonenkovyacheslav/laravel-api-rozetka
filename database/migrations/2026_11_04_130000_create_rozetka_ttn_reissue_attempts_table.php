<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRozetkaTtnReissueAttemptsTable extends Migration
{
    public function up()
    {
        Schema::create('rozetka_ttn_reissue_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->string('old_tracking_number', 64);
            $table->string('new_tracking_number', 64)->nullable();
            $table->string('status', 32);
            $table->text('cargo_seats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['order_id', 'old_tracking_number'],
                'rozetka_ttn_reissue_order_ttn_unique'
            );
            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('rozetka_ttn_reissue_attempts');
    }
}
