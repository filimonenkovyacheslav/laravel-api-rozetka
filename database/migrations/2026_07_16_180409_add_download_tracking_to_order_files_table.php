<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDownloadTrackingToOrderFilesTable extends Migration
{
    public function up()
    {
        Schema::table('order_files', function (Blueprint $table) {
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->string('downloaded_by', 100)->nullable();

            $table->index(
                'downloaded_at',
                'order_files_downloaded_at_idx'
            );
        });
    }

    public function down()
    {
        Schema::table('order_files', function (Blueprint $table) {
            $table->dropIndex('order_files_downloaded_at_idx');

            $table->dropColumn([
                'downloaded_at',
                'download_count',
                'downloaded_by',
            ]);
        });
    }
}