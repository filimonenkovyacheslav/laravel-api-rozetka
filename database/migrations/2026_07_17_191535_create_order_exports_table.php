<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderExportsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_exports', function (Blueprint $table) {
            $table->id();

            $table->dateTime('period_from')->nullable();
            $table->dateTime('period_to')->nullable();

            $table->string('file_name');
            $table->string('file_path')->nullable();

            $table->string('recipient_email')->nullable();
            $table->string('status', 30)->default('pending');

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->text('error_message')->nullable();
            $table->string('created_by', 100)->nullable();

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_exports');
    }
}
