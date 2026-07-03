<?php

use App\Classes\Schema;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Introduces the catalog attributes required by liquor retail:
     * brands, HSN codes, alcohol strength, bottle volume, MRP and
     * procurement batch numbers.
     */
    public function up(): void
    {
        if ( ! Schema::hasTable( 'nexopos_brands' ) ) {
            Schema::create( 'nexopos_brands', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                $table->string( 'name' )->unique();
                $table->text( 'description' )->nullable();
                $table->integer( 'author_id' );
                $table->string( 'uuid' )->nullable();
                $table->timestamps();
            } );
        }

        if ( Schema::hasTable( 'nexopos_products' ) ) {
            Schema::table( 'nexopos_products', function ( Blueprint $table ) {
                if ( ! Schema::hasColumn( 'nexopos_products', 'brand_id' ) ) {
                    $table->integer( 'brand_id' )->nullable()->index( 'idx_products_brand_id' );
                }

                if ( ! Schema::hasColumn( 'nexopos_products', 'hsn_code' ) ) {
                    $table->string( 'hsn_code' )->nullable();
                }

                if ( ! Schema::hasColumn( 'nexopos_products', 'alcohol_percentage' ) ) {
                    $table->decimal( 'alcohol_percentage', 8, 2 )->nullable();
                }

                if ( ! Schema::hasColumn( 'nexopos_products', 'volume_ml' ) ) {
                    $table->decimal( 'volume_ml', 10, 2 )->nullable();
                }
            } );
        }

        if ( Schema::hasTable( 'nexopos_products_unit_quantities' ) && ! Schema::hasColumn( 'nexopos_products_unit_quantities', 'mrp' ) ) {
            Schema::table( 'nexopos_products_unit_quantities', function ( Blueprint $table ) {
                $table->decimal( 'mrp', 18, 5 )->default( 0 );
            } );
        }

        if ( Schema::hasTable( 'nexopos_procurements_products' ) && ! Schema::hasColumn( 'nexopos_procurements_products', 'batch_number' ) ) {
            Schema::table( 'nexopos_procurements_products', function ( Blueprint $table ) {
                $table->string( 'batch_number' )->nullable();
            } );
        }

        if ( Schema::hasTable( 'nexopos_orders_products' ) && ! Schema::hasColumn( 'nexopos_orders_products', 'hsn_code' ) ) {
            Schema::table( 'nexopos_orders_products', function ( Blueprint $table ) {
                $table->string( 'hsn_code' )->nullable();
            } );
        }

        /**
         * Registers the brands crud permissions and assigns
         * them to the administrative roles.
         */
        if ( ! defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
            define( 'NEXO_CREATE_PERMISSIONS', true );
        }

        $permissions = [];

        foreach ( [ 'create', 'read', 'update', 'delete' ] as $crud ) {
            $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.' . $crud . '.brands' ] );
            $permission->name = ucwords( $crud ) . ' ' . __( 'Brands' );
            $permission->namespace = 'nexopos.' . $crud . '.brands';
            $permission->description = sprintf( __( 'Can %s brands' ), $crud );
            $permission->save();

            $permissions[] = $permission->namespace;
        }

        foreach ( [ Role::ADMIN, Role::STOREADMIN ] as $roleNamespace ) {
            $role = Role::firstOrNew( [ 'namespace' => $roleNamespace ] );

            if ( $role->exists ) {
                $role->addPermissions( $permissions );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists( 'nexopos_brands' );

        if ( Schema::hasTable( 'nexopos_products' ) ) {
            Schema::table( 'nexopos_products', function ( Blueprint $table ) {
                foreach ( [ 'brand_id', 'hsn_code', 'alcohol_percentage', 'volume_ml' ] as $column ) {
                    if ( Schema::hasColumn( 'nexopos_products', $column ) ) {
                        $table->dropColumn( $column );
                    }
                }
            } );
        }

        if ( Schema::hasTable( 'nexopos_products_unit_quantities' ) && Schema::hasColumn( 'nexopos_products_unit_quantities', 'mrp' ) ) {
            Schema::table( 'nexopos_products_unit_quantities', function ( Blueprint $table ) {
                $table->dropColumn( 'mrp' );
            } );
        }

        if ( Schema::hasTable( 'nexopos_procurements_products' ) && Schema::hasColumn( 'nexopos_procurements_products', 'batch_number' ) ) {
            Schema::table( 'nexopos_procurements_products', function ( Blueprint $table ) {
                $table->dropColumn( 'batch_number' );
            } );
        }

        if ( Schema::hasTable( 'nexopos_orders_products' ) && Schema::hasColumn( 'nexopos_orders_products', 'hsn_code' ) ) {
            Schema::table( 'nexopos_orders_products', function ( Blueprint $table ) {
                $table->dropColumn( 'hsn_code' );
            } );
        }
    }
};
