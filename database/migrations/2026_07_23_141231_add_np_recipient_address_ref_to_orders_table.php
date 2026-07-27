<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNpRecipientAddressRefToOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table
                ->string(
                    'np_recipient_address_ref',
                    36
                )
                ->nullable()
                ->after('np_recipient_contact_ref');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(
                'np_recipient_address_ref'
            );
        });
    }
}