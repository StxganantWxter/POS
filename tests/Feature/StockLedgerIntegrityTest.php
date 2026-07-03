<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Product;
use App\Models\ProductAdjustment;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\OrdersService;
use App\Services\ProductService;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class StockLedgerIntegrityTest extends TestCase
{
    use WithAuthentication;

    /**
     * Creates a stock managed product carrying
     * an initial quantity for the base unit.
     */
    private function createStockedProduct( float $quantity ): ProductUnitQuantity
    {
        $group = new UnitGroup;
        $group->name = 'Ledger Test Group ' . uniqid();
        $group->author_id = 1;
        $group->save();

        $unit = new Unit;
        $unit->name = 'Ledger Test Unit ' . uniqid();
        $unit->identifier = 'ledger-test-' . uniqid();
        $unit->group_id = $group->id;
        $unit->base_unit = true;
        $unit->value = 1;
        $unit->author_id = 1;
        $unit->save();

        $product = new Product;
        $product->name = 'Ledger Test Product ' . uniqid();
        $product->product_type = 'product';
        $product->type = Product::TYPE_MATERIALIZED;
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->barcode = strtoupper( uniqid() );
        $product->barcode_type = 'code128';
        $product->sku = strtoupper( uniqid() );
        $product->unit_group = $group->id;
        $product->author_id = 1;
        $product->save();

        $unitQuantity = new ProductUnitQuantity;
        $unitQuantity->product_id = $product->id;
        $unitQuantity->unit_id = $unit->id;
        $unitQuantity->quantity = $quantity;
        $unitQuantity->sale_price = 100;
        $unitQuantity->sale_price_edit = 100;
        $unitQuantity->save();

        return $unitQuantity;
    }

    public function test_adjustment_reason_is_recorded_on_stock_ledger()
    {
        $this->attemptAuthenticate();

        $unitQuantity = $this->createStockedProduct( 50 );

        /**
         * @var ProductService $productService
         */
        $productService = app()->make( ProductService::class );

        $reason = 'Broken bottles during delivery';

        $history = $productService->stockAdjustment( ProductHistory::ACTION_DEFECTIVE, [
            'product_id' => $unitQuantity->product_id,
            'unit_id' => $unitQuantity->unit_id,
            'unit_price' => 100,
            'quantity' => 5,
            'description' => $reason,
        ] );

        $this->assertInstanceOf( ProductHistory::class, $history );
        $this->assertEquals( $reason, $history->fresh()->description );
        $this->assertEquals( 45, (float) $unitQuantity->fresh()->quantity );
    }

    public function test_adjustment_batch_reason_reaches_the_ledger()
    {
        $this->attemptAuthenticate();

        $unitQuantity = $this->createStockedProduct( 30 );

        /**
         * @var ProductService $productService
         */
        $productService = app()->make( ProductService::class );

        $adjustment = $productService->createAdjustmentDraft( [
            [
                'id' => $unitQuantity->product_id,
                'adjust_action' => ProductHistory::ACTION_DEFECTIVE,
                'adjust_quantity' => 3,
                'adjust_reason' => 'Breakage while stacking',
                'adjust_unit' => [
                    'unit_id' => $unitQuantity->unit_id,
                    'sale_price' => 100,
                ],
            ],
        ] );

        $productService->executeAdjustmentDraft( $adjustment );

        $history = ProductHistory::where( 'product_id', $unitQuantity->product_id )
            ->where( 'operation_type', ProductHistory::ACTION_DEFECTIVE )
            ->orderBy( 'id', 'desc' )
            ->first();

        $this->assertInstanceOf( ProductHistory::class, $history );
        $this->assertEquals( 'Breakage while stacking', $history->description );

        $adjustment = $adjustment->fresh();

        $this->assertEquals( ProductAdjustment::STATUS_PERFORMED, $adjustment->status );
        $this->assertNotNull( $adjustment->approved_by, 'The approver should be recorded on the executed batch.' );
        $this->assertNotNull( $adjustment->approved_at, 'The approval date should be recorded on the executed batch.' );
    }

    public function test_failed_adjustment_batch_is_fully_rolled_back()
    {
        $this->attemptAuthenticate();

        $unitQuantity = $this->createStockedProduct( 50 );

        /**
         * @var ProductService $productService
         */
        $productService = app()->make( ProductService::class );

        $adjustment = $productService->createAdjustmentDraft( [
            [
                'id' => $unitQuantity->product_id,
                'adjust_action' => ProductHistory::ACTION_DEFECTIVE,
                'adjust_quantity' => 10,
                'adjust_reason' => 'First line applies fine',
                'adjust_unit' => [
                    'unit_id' => $unitQuantity->unit_id,
                    'sale_price' => 100,
                ],
            ],
            [
                'id' => $unitQuantity->product_id,
                'adjust_action' => ProductHistory::ACTION_DEFECTIVE,
                'adjust_quantity' => 999,
                'adjust_reason' => 'Second line would cause negative stock',
                'adjust_unit' => [
                    'unit_id' => $unitQuantity->unit_id,
                    'sale_price' => 100,
                ],
            ],
        ] );

        $historyCount = ProductHistory::where( 'product_id', $unitQuantity->product_id )->count();

        try {
            $productService->executeAdjustmentDraft( $adjustment );
            $this->fail( 'Executing a batch that causes negative stock should throw an exception.' );
        } catch ( NotAllowedException $exception ) {
            // expected: the second line exceeds the available quantity.
        }

        $this->assertEquals( 50, (float) $unitQuantity->fresh()->quantity, 'The stock of the first line should have been restored.' );
        $this->assertEquals( ProductAdjustment::STATUS_DRAFT, $adjustment->fresh()->status, 'The batch should remain a draft.' );
        $this->assertEquals(
            $historyCount,
            ProductHistory::where( 'product_id', $unitQuantity->product_id )->count(),
            'No ledger entry should remain from the rolled back batch.'
        );
    }

    public function test_order_codes_are_unique_and_sequential()
    {
        $this->attemptAuthenticate();

        /**
         * @var OrdersService $ordersService
         */
        $ordersService = app()->make( OrdersService::class );

        $fakeOrder = (object) [ 'created_at' => ns()->date->getNow()->toDateTimeString() ];

        $codes = collect( range( 1, 5 ) )
            ->map( fn( $index ) => $ordersService->generateOrderCode( $fakeOrder ) );

        $this->assertEquals( 5, $codes->unique()->count(), 'Generated order codes should be unique.' );

        $sequence = $codes->map( fn( $code ) => (int) substr( $code, strrpos( $code, '-' ) + 1 ) )->toArray();
        $expected = range( $sequence[0], $sequence[0] + 4 );

        $this->assertEquals( $expected, $sequence, 'Order codes should increment sequentially.' );
    }
}
