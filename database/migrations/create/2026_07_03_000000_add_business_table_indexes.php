<?php

use App\Classes\Hook;
use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as BaseSchema;

return new class extends Migration
{
    /**
     * Indexes required to keep ledger views, reports and POS lookups
     * fast once tables grow past tens of thousands of rows.
     *
     * Every definition is guarded so the migration stays idempotent and
     * safe to run on databases where some indexes already exist.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    protected array $indexes = [
        'nexopos_products_histories' => [
            'idx_ph_product_unit_date' => [ 'product_id', 'unit_id', 'created_at' ],
            'idx_ph_operation_type' => [ 'operation_type' ],
            'idx_ph_order_id' => [ 'order_id' ],
            'idx_ph_procurement_id' => [ 'procurement_id' ],
            'idx_ph_created_at' => [ 'created_at' ],
        ],
        'nexopos_products_histories_combined' => [
            'idx_phc_date' => [ 'date' ],
            'idx_phc_product_unit' => [ 'product_id', 'unit_id' ],
        ],
        'nexopos_products_unit_quantities' => [
            'idx_puq_product_unit' => [ 'product_id', 'unit_id' ],
        ],
        'nexopos_orders' => [
            'idx_orders_created_at' => [ 'created_at' ],
            'idx_orders_customer_id' => [ 'customer_id' ],
            'idx_orders_payment_status' => [ 'payment_status' ],
            'idx_orders_code' => [ 'code' ],
        ],
        'nexopos_orders_products' => [
            'idx_op_order_id' => [ 'order_id' ],
            'idx_op_product_created' => [ 'product_id', 'created_at' ],
        ],
        'nexopos_orders_payments' => [
            'idx_opay_order_id' => [ 'order_id' ],
        ],
        'nexopos_procurements_products' => [
            'idx_pp_procurement_id' => [ 'procurement_id' ],
            'idx_pp_product_id' => [ 'product_id' ],
        ],
        'nexopos_transactions_histories' => [
            'idx_th_account_date' => [ 'transaction_account_id', 'trigger_date' ],
            'idx_th_order_id' => [ 'order_id' ],
            'idx_th_procurement_id' => [ 'procurement_id' ],
        ],
        'nexopos_customers_account_history' => [
            'idx_cah_customer_id' => [ 'customer_id' ],
        ],
        'nexopos_registers_history' => [
            'idx_rh_register_date' => [ 'register_id', 'created_at' ],
        ],
    ];

    public function up(): void
    {
        foreach ( $this->indexes as $table => $definitions ) {
            if ( ! Schema::hasTable( $table ) ) {
                continue;
            }

            foreach ( $definitions as $name => $columns ) {
                if ( ! Schema::hasColumns( $table, $columns ) || $this->hasIndex( $table, $name ) ) {
                    continue;
                }

                Schema::table( $table, function ( Blueprint $blueprint ) use ( $columns, $name ) {
                    $blueprint->index( $columns, $name );
                } );
            }
        }

        /**
         * The daily order counter must hold a single row per day so that
         * OrdersService::generateOrderCode can increment it atomically.
         * Existing databases might hold duplicated rows, in which case the
         * unique index is skipped rather than failing the migration.
         */
        if ( Schema::hasTable( 'nexopos_orders_count' ) && ! $this->hasIndex( 'nexopos_orders_count', 'uq_orders_count_date' ) ) {
            try {
                Schema::table( 'nexopos_orders_count', function ( Blueprint $blueprint ) {
                    $blueprint->unique( 'date', 'uq_orders_count_date' );
                } );
            } catch ( Throwable $exception ) {
                // duplicated rows prevent the unique index: leave the table untouched.
            }
        }
    }

    public function down(): void
    {
        foreach ( $this->indexes as $table => $definitions ) {
            if ( ! Schema::hasTable( $table ) ) {
                continue;
            }

            foreach ( array_keys( $definitions ) as $name ) {
                if ( $this->hasIndex( $table, $name ) ) {
                    Schema::table( $table, function ( Blueprint $blueprint ) use ( $name ) {
                        $blueprint->dropIndex( $name );
                    } );
                }
            }
        }

        if ( Schema::hasTable( 'nexopos_orders_count' ) && $this->hasIndex( 'nexopos_orders_count', 'uq_orders_count_date' ) ) {
            Schema::table( 'nexopos_orders_count', function ( Blueprint $blueprint ) {
                $blueprint->dropUnique( 'uq_orders_count_date' );
            } );
        }
    }

    protected function hasIndex( string $table, string $name ): bool
    {
        $indexes = BaseSchema::getIndexes( Hook::filter( 'ns-table-name', $table ) );

        return collect( $indexes )->pluck( 'name' )->contains( $name );
    }
};
