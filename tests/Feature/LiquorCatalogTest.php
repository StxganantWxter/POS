<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class LiquorCatalogTest extends TestCase
{
    use WithAuthentication;

    private function createUnitGroup(): UnitGroup
    {
        $group = new UnitGroup;
        $group->name = 'Liquor Units ' . uniqid();
        $group->author_id = 1;
        $group->save();

        foreach ( [ [ 'Bottle', 1, true ], [ 'Case', 12, false ] ] as [ $name, $value, $base ] ) {
            $unit = new Unit;
            $unit->name = $name . ' ' . uniqid();
            $unit->identifier = Str::slug( $unit->name );
            $unit->group_id = $group->id;
            $unit->base_unit = $base;
            $unit->value = $value;
            $unit->author_id = 1;
            $unit->save();
        }

        return $group->fresh();
    }

    public function test_create_brand_through_crud_api()
    {
        $this->attemptAuthenticate();

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.products-brands', [
                'name' => 'Royal Test Brand ' . uniqid(),
                'general' => [
                    'description' => 'Created via tests',
                ],
            ] );

        $response->assertJson( [ 'status' => 'success' ] );

        $brand = Brand::find( $response->json()[ 'data' ][ 'entry' ][ 'id' ] );

        $this->assertInstanceOf( Brand::class, $brand );
    }

    public function test_create_product_with_liquor_attributes()
    {
        $this->attemptAuthenticate();

        $brand = new Brand;
        $brand->name = 'Test Distillery ' . uniqid();
        $brand->author_id = 1;
        $brand->save();

        $categoryResponse = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.products-categories', [
                'name' => 'Whisky ' . uniqid(),
                'general' => [
                    'displays_on_pos' => true,
                ],
            ] );

        $categoryResponse->assertJson( [ 'status' => 'success' ] );
        $categoryId = $categoryResponse->json()[ 'data' ][ 'entry' ][ 'id' ];

        $unitGroup = $this->createUnitGroup();

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', '/api/products/', [
                'name' => 'Test Whisky 750ml ' . uniqid(),
                'variations' => [
                    [
                        '$primary' => true,
                        'expiracy' => [
                            'expires' => 0,
                            'on_expiration' => 'prevent_sales',
                        ],
                        'identification' => [
                            'barcode' => '890123456' . rand( 1000, 9999 ),
                            'barcode_type' => 'code128',
                            'searchable' => true,
                            'category_id' => $categoryId,
                            'brand_id' => $brand->id,
                            'hsn_code' => '22083011',
                            'alcohol_percentage' => 42.8,
                            'volume_ml' => 750,
                            'description' => 'Created via tests',
                            'product_type' => 'product',
                            'type' => Product::TYPE_MATERIALIZED,
                            'sku' => Str::random( 15 ) . '-sku',
                            'status' => 'available',
                            'stock_management' => 'enabled',
                        ],
                        'images' => [],
                        'units' => [
                            'selling_group' => $unitGroup->units->map( function ( $unit ) {
                                return [
                                    'sale_price_edit' => 500,
                                    'wholesale_price_edit' => 450,
                                    'mrp' => 550,
                                    'unit_id' => $unit->id,
                                ];
                            } )->toArray(),
                            'unit_group' => $unitGroup->id,
                        ],
                    ],
                ],
            ] );

        $response->assertStatus( 200 );

        $product = Product::find( $response->json()[ 'data' ][ 'product' ][ 'id' ] );

        $this->assertEquals( $brand->id, $product->brand_id );
        $this->assertEquals( '22083011', $product->hsn_code );
        $this->assertEquals( 42.8, (float) $product->alcohol_percentage );
        $this->assertEquals( 750, (float) $product->volume_ml );
        $this->assertInstanceOf( Brand::class, $product->brand );

        $product->unit_quantities->each( function ( $unitQuantity ) {
            $this->assertEquals( 550, (float) $unitQuantity->mrp, 'The MRP should be saved on every unit quantity.' );
        } );

        $this->assertTrue(
            $brand->products()->where( 'id', $product->id )->exists(),
            'The product should be listed under the brand.'
        );
    }

    public function test_brands_permissions_are_assigned()
    {
        $this->attemptAuthenticate();

        foreach ( [ 'create', 'read', 'update', 'delete' ] as $crud ) {
            $this->assertDatabaseHas( 'nexopos_permissions', [
                'namespace' => 'nexopos.' . $crud . '.brands',
            ] );
        }
    }
}
