<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddRozetkaApiFieldsToOrdersAndOrderItemsTables extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table
                ->string('order_num', 100)
                ->nullable()
                ->after('guid')
                ->index();

            $table
                ->string('rozetka_status', 30)
                ->nullable()
                ->after('status')
                ->index();

            $table
                ->integer('tracking_status_code')
                ->nullable()
                ->after('tracking_number');

            $table
                ->string('tracking_status', 255)
                ->nullable()
                ->after('tracking_status_code');

            $table
                ->string('return_ttn', 100)
                ->nullable()
                ->after('tracking_status');

            $table
                ->text('document_link')
                ->nullable()
                ->after('return_ttn');

            $table
                ->string('incoming_invoice_number', 191)
                ->nullable()
                ->after('document_link');

            $table
                ->boolean('sn_required')
                ->nullable()
                ->after('incoming_invoice_number');

            $table
                ->timestamp('rozetka_synced_at')
                ->nullable()
                ->after('updated_at_partner')
                ->index();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table
                ->unsignedInteger('confirmed_quantity')
                ->nullable()
                ->after('quantity');
        });

        /*
         * Новый Dropshipping API Rozetka не возвращает price.
         * Поэтому для новых заказов цена пока должна допускать NULL.
         *
         * Используем raw SQL, чтобы не добавлять doctrine/dbal
         * только ради change().
         */
        DB::statement(
            'ALTER TABLE order_items
             MODIFY price DECIMAL(10,2) NULL'
        );
    }

    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('confirmed_quantity');
        });

        /*
         * Перед возвратом NOT NULL заменяем возможные NULL на 0,
         * иначе MySQL не даст вернуть старую структуру.
         */
        DB::table('order_items')
            ->whereNull('price')
            ->update([
                'price' => 0,
            ]);

        DB::statement(
            'ALTER TABLE order_items
             MODIFY price DECIMAL(10,2) NOT NULL'
        );

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex([
                'order_num',
            ]);

            $table->dropIndex([
                'rozetka_status',
            ]);

            $table->dropIndex([
                'rozetka_synced_at',
            ]);

            $table->dropColumn([
                'order_num',
                'rozetka_status',
                'tracking_status_code',
                'tracking_status',
                'return_ttn',
                'document_link',
                'incoming_invoice_number',
                'sn_required',
                'rozetka_synced_at',
            ]);
        });
    }
}