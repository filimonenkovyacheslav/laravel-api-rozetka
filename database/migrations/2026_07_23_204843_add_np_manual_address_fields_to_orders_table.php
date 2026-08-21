<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNpManualAddressFieldsToOrdersTable extends Migration
{
    public function up()
    {
        /*
         * Проверки нужны потому, что часть полей могла быть
         * ранее добавлена вручную через SQL.
         */
        $addSettlementRef = !Schema::hasColumn(
            'orders',
            'np_recipient_settlement_ref'
        );

        $addSettlementName = !Schema::hasColumn(
            'orders',
            'np_recipient_settlement_name'
        );

        $addStreetRef = !Schema::hasColumn(
            'orders',
            'np_recipient_street_ref'
        );

        $addStreetName = !Schema::hasColumn(
            'orders',
            'np_recipient_street_name'
        );

        $addStreetSource = !Schema::hasColumn(
            'orders',
            'np_recipient_street_source'
        );

        Schema::table('orders', function (Blueprint $table) use (
            $addSettlementRef,
            $addSettlementName,
            $addStreetRef,
            $addStreetName,
            $addStreetSource
        ) {
            if ($addSettlementRef) {
                $table
                    ->string(
                        'np_recipient_settlement_ref',
                        36
                    )
                    ->nullable()
                    ->after('np_city_ref')
                    ->index();
            }

            if ($addSettlementName) {
                $table
                    ->string(
                        'np_recipient_settlement_name',
                        255
                    )
                    ->nullable()
                    ->after('np_recipient_settlement_ref');
            }

            if ($addStreetRef) {
                $table
                    ->string(
                        'np_recipient_street_ref',
                        36
                    )
                    ->nullable()
                    ->after('np_recipient_settlement_name')
                    ->index();
            }

            if ($addStreetName) {
                $table
                    ->string(
                        'np_recipient_street_name',
                        255
                    )
                    ->nullable()
                    ->after('np_recipient_street_ref');
            }

            if ($addStreetSource) {
                $table
                    ->string(
                        'np_recipient_street_source',
                        20
                    )
                    ->nullable()
                    ->after('np_recipient_street_name');
            }
        });
    }

    public function down()
    {
        $columns = [];

        if (Schema::hasColumn(
            'orders',
            'np_recipient_street_source'
        )) {
            $columns[] = 'np_recipient_street_source';
        }

        if (Schema::hasColumn(
            'orders',
            'np_recipient_street_name'
        )) {
            $columns[] = 'np_recipient_street_name';
        }

        if (Schema::hasColumn(
            'orders',
            'np_recipient_street_ref'
        )) {
            $columns[] = 'np_recipient_street_ref';
        }

        if (Schema::hasColumn(
            'orders',
            'np_recipient_settlement_name'
        )) {
            $columns[] = 'np_recipient_settlement_name';
        }

        if (Schema::hasColumn(
            'orders',
            'np_recipient_settlement_ref'
        )) {
            $columns[] = 'np_recipient_settlement_ref';
        }

        if (!empty($columns)) {
            Schema::table(
                'orders',
                function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                }
            );
        }
    }
}