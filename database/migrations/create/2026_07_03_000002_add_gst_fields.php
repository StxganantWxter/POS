<?php

use App\Classes\Schema;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * GST identifiers: customers (stored on nexopos_users) and
     * suppliers both carry an optional GSTIN used on invoices and
     * on the GST reports.
     */
    public function up(): void
    {
        if ( Schema::hasTable( 'nexopos_users' ) && ! Schema::hasColumn( 'nexopos_users', 'gstin' ) ) {
            Schema::table( 'nexopos_users', function ( Blueprint $table ) {
                $table->string( 'gstin', 20 )->nullable();
            } );
        }

        if ( Schema::hasTable( 'nexopos_providers' ) && ! Schema::hasColumn( 'nexopos_providers', 'gstin' ) ) {
            Schema::table( 'nexopos_providers', function ( Blueprint $table ) {
                $table->string( 'gstin', 20 )->nullable();
            } );
        }

        /**
         * Registers the GST report permission and assigns it
         * to the administrative roles.
         */
        if ( ! defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
            define( 'NEXO_CREATE_PERMISSIONS', true );
        }

        $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.reports.gst' ] );
        $permission->name = __( 'GST Report' );
        $permission->namespace = 'nexopos.reports.gst';
        $permission->description = __( 'Can access the GST report' );
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
        if ( Schema::hasTable( 'nexopos_users' ) && Schema::hasColumn( 'nexopos_users', 'gstin' ) ) {
            Schema::table( 'nexopos_users', function ( Blueprint $table ) {
                $table->dropColumn( 'gstin' );
            } );
        }

        if ( Schema::hasTable( 'nexopos_providers' ) && Schema::hasColumn( 'nexopos_providers', 'gstin' ) ) {
            Schema::table( 'nexopos_providers', function ( Blueprint $table ) {
                $table->dropColumn( 'gstin' );
            } );
        }
    }
};
