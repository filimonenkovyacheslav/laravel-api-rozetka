<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRozetkaAutoTtnAttemptsTable extends Migration
{
    public function up()
    {
        Schema::create('rozetka_auto_ttn_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('status', 32);
            $table->string('tracking_number', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('rozetka_auto_ttn_attempts');
    }
}
