<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTtnBatchIdToOrderExportsTable extends Migration
{
    public function up()
    {
        Schema::table('order_exports', function (Blueprint $table) {
            $table
                ->unsignedBigInteger('ttn_batch_id')
                ->nullable()
                ->after('id');

            $table->index(
                'ttn_batch_id',
                'order_exports_ttn_batch_idx'
            );

            $table
                ->foreign(
                    'ttn_batch_id',
                    'order_exports_ttn_batch_fk'
                )
                ->references('id')
                ->on('ttn_batches')
                ->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('order_exports', function (Blueprint $table) {
            $table->dropForeign(
                'order_exports_ttn_batch_fk'
            );

            $table->dropIndex(
                'order_exports_ttn_batch_idx'
            );

            $table->dropColumn('ttn_batch_id');
        });
    }
}