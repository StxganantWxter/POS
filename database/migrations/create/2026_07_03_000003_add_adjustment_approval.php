<?php

use App\Classes\Schema;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Separates drafting stock adjustments from approving them:
     * executing a draft (posting it to the stock ledger) requires a
     * dedicated permission, and the batch records who approved it.
     */
    public function up(): void
    {
        if ( Schema::hasTable( 'nexopos_products_adjustments' ) ) {
            Schema::table( 'nexopos_products_adjustments', function ( Blueprint $table ) {
                if ( ! Schema::hasColumn( 'nexopos_products_adjustments', 'approved_by' ) ) {
                    $table->integer( 'approved_by' )->nullable();
                }

                if ( ! Schema::hasColumn( 'nexopos_products_adjustments', 'approved_at' ) ) {
                    $table->datetime( 'approved_at' )->nullable();
                }
            } );
        }

        if ( ! defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
            define( 'NEXO_CREATE_PERMISSIONS', true );
        }

        $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.approve.products-adjustments' ] );
        $permission->name = __( 'Approve Stock Adjustments' );
        $permission->namespace = 'nexopos.approve.products-adjustments';
        $permission->description = __( 'Can approve and execute drafted stock adjustments' );
        $permission->save();

        foreach ( [ Role::ADMIN, Role::STOREADMIN ] as $roleNamespace ) {
            $role = Role::firstOrNew( [ 'namespace' => $roleNamespace ] );

            if ( $role->exists ) {
                $role->addPermissions( [ $permission->namespace ] );
            }
        }
    }

    public function down(): void
    {
        if ( Schema::hasTable( 'nexopos_products_adjustments' ) ) {
            Schema::table( 'nexopos_products_adjustments', function ( Blueprint $table ) {
                foreach ( [ 'approved_by', 'approved_at' ] as $column ) {
                    if ( Schema::hasColumn( 'nexopos_products_adjustments', $column ) ) {
                        $table->dropColumn( $column );
                    }
                }
            } );
        }
    }
};
