<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNovaPoshtaFieldsToOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table
                ->string('np_service_type', 30)
                ->nullable()
                ->after('ttn_created_at');

            $table
                ->string('np_city_ref', 36)
                ->nullable()
                ->after('np_service_type');

            $table
                ->string('np_warehouse_ref', 36)
                ->nullable()
                ->after('np_city_ref');

            $table
                ->string('np_recipient_ref', 36)
                ->nullable()
                ->after('np_warehouse_ref');

            $table
                ->string('np_recipient_contact_ref', 36)
                ->nullable()
                ->after('np_recipient_ref');

            $table
                ->string('np_document_ref', 36)
                ->nullable()
                ->after('np_recipient_contact_ref');

            $table->index(
                'np_document_ref',
                'orders_np_document_ref_idx'
            );
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_np_document_ref_idx');

            $table->dropColumn([
                'np_service_type',
                'np_city_ref',
                'np_warehouse_ref',
                'np_recipient_ref',
                'np_recipient_contact_ref',
                'np_document_ref',
            ]);
        });
    }
}