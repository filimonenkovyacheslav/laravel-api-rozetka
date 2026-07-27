<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTtnBatchesTable extends Migration
{
    public function up()
    {
        Schema::create('ttn_batches', function (Blueprint $table) {
            $table->id();

            $table
                ->string('status', 20)
                ->default('pending');

            $table
                ->unsignedInteger('total_count')
                ->default(0);

            $table
                ->unsignedInteger('success_count')
                ->default(0);

            $table
                ->unsignedInteger('failed_count')
                ->default(0);

            $table
                ->string('created_by', 100)
                ->nullable();

            $table
                ->timestamp('started_at')
                ->nullable();

            $table
                ->timestamp('completed_at')
                ->nullable();

            $table->timestamps();

            $table->index(
                'status',
                'ttn_batches_status_idx'
            );

            $table->index(
                'created_at',
                'ttn_batches_created_at_idx'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('ttn_batches');
    }
}