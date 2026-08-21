<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNameToProductDimensionsTable extends Migration
{
    public function up()
    {
        Schema::table('product_dimensions', function (Blueprint $table) {
            $table
                ->string('name', 255)
                ->nullable()
                ->after('rz_code');
        });
    }

    public function down()
    {
        Schema::table('product_dimensions', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
}