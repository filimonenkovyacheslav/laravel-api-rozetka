<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductDimensionPlacesTable extends Migration
{
    public function up()
    {
        Schema::create('product_dimension_places', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('product_dimension_id');
            $table->unsignedInteger('place_number');

            $table->decimal('weight', 10, 3);
            $table->decimal('length', 10, 2);
            $table->decimal('width', 10, 2);
            $table->decimal('height', 10, 2);

            $table->timestamps();

            $table->foreign(
                'product_dimension_id',
                'pdp_product_fk'
            )
                ->references('id')
                ->on('product_dimensions')
                ->onDelete('cascade');

            $table->unique(
                ['product_dimension_id', 'place_number'],
                'pdp_product_place_unique'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_dimension_places');
    }
}