<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Services\ReportService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class GstReportTest extends TestCase
{
    use WithAuthentication;

    private function createOrderWithProducts( array $lines, string $paymentStatus = Order::PAYMENT_PAID ): Order
    {
        $order = new Order;
        $order->code = 'GSTTEST-' . uniqid();
        $order->type = 'takeaway';
        $order->payment_status = $paymentStatus;
        $order->customer_id = 1;
        $order->author_id = Auth::id();
        $order->subtotal = collect( $lines )->sum( 'total_price' );
        $order->total = $order->subtotal;
        $order->created_at = ns()->date->getNow()->toDateTimeString();
        $order->updated_at = ns()->date->getNow()->toDateTimeString();
        $order->save();

        foreach ( $lines as $line ) {
            $orderProduct = new OrderProduct;
            $orderProduct->order_id = $order->id;
            $orderProduct->name = 'GST Test Product';
            $orderProduct->product_id = 0;
            $orderProduct->unit_id = 1;
            $orderProduct->unit_quantity_id = 1;
            $orderProduct->product_category_id = 0;
            $orderProduct->status = $line[ 'status' ] ?? 'sold';
            $orderProduct->hsn_code = $line[ 'hsn_code' ];
            $orderProduct->rate = $line[ 'rate' ];
            $orderProduct->quantity = $line[ 'quantity' ];
            $orderProduct->unit_price = $line[ 'quantity' ] > 0 ? $line[ 'total_price' ] / $line[ 'quantity' ] : 0;
            $orderProduct->total_price = $line[ 'total_price' ];
            $orderProduct->total_price_net = $line[ 'total_price_net' ];
            $orderProduct->tax_value = $line[ 'tax_value' ];
            $orderProduct->save();
        }

        return $order;
    }

    public function test_gst_report_groups_sales_by_hsn_and_rate()
    {
        $this->attemptAuthenticate();

        /**
         * unique HSN codes keep this test isolated
         * from rows created by previous runs.
         */
        $whiskyHsn = 'W' . rand( 10000000, 99999999 );
        $beerHsn = 'B' . rand( 10000000, 99999999 );

        $this->createOrderWithProducts( [
            [ 'hsn_code' => $whiskyHsn, 'rate' => 18, 'quantity' => 2, 'total_price' => 1180, 'total_price_net' => 1000, 'tax_value' => 180 ],
            [ 'hsn_code' => $whiskyHsn, 'rate' => 18, 'quantity' => 1, 'total_price' => 590, 'total_price_net' => 500, 'tax_value' => 90 ],
            [ 'hsn_code' => $beerHsn, 'rate' => 28, 'quantity' => 4, 'total_price' => 1280, 'total_price_net' => 1000, 'tax_value' => 280 ],
        ] );

        /**
         * Voided orders should be excluded from the report.
         */
        $this->createOrderWithProducts( [
            [ 'hsn_code' => $whiskyHsn, 'rate' => 18, 'quantity' => 5, 'total_price' => 5900, 'total_price_net' => 5000, 'tax_value' => 900 ],
        ], Order::PAYMENT_VOID );

        $report = app()->make( ReportService::class )->getGstReport(
            ns()->date->getNow()->startOfDay()->toDateTimeString(),
            ns()->date->getNow()->endOfDay()->toDateTimeString()
        );

        $whiskyRow = $report[ 'sales' ]->firstWhere( 'hsn_code', $whiskyHsn );

        $this->assertNotNull( $whiskyRow, 'The report should include the whisky HSN group.' );
        $this->assertEquals( 3, (float) $whiskyRow->quantity, 'Voided orders should be excluded from the group.' );
        $this->assertEquals( 1500, (float) $whiskyRow->taxable_value );
        $this->assertEquals( 270, (float) $whiskyRow->tax_value );
        $this->assertEquals( 135, (float) $whiskyRow->cgst );
        $this->assertEquals( 135, (float) $whiskyRow->sgst );

        $beerRow = $report[ 'sales' ]->firstWhere( 'hsn_code', $beerHsn );

        $this->assertNotNull( $beerRow, 'The report should include the beer HSN group.' );
        $this->assertEquals( 280, (float) $beerRow->tax_value );
    }

    public function test_gst_report_includes_net_of_refund_lines()
    {
        $this->attemptAuthenticate();

        $hsn = 'R' . rand( 10000000, 99999999 );

        /**
         * a partial refund leaves the order product with status
         * 'returned' and its quantity/tax already reduced to the kept
         * (still sold) portion. That net supply must remain on the GST
         * report; only the fully refunded (quantity 0) line drops out.
         */
        $this->createOrderWithProducts( [
            // fully sold line
            [ 'hsn_code' => $hsn, 'rate' => 18, 'quantity' => 2, 'total_price' => 1180, 'total_price_net' => 1000, 'tax_value' => 180 ],
            // partially refunded line: kept 1 unit
            [ 'hsn_code' => $hsn, 'rate' => 18, 'quantity' => 1, 'total_price' => 590, 'total_price_net' => 500, 'tax_value' => 90, 'status' => 'returned' ],
            // fully refunded line: nothing kept
            [ 'hsn_code' => $hsn, 'rate' => 18, 'quantity' => 0, 'total_price' => 0, 'total_price_net' => 0, 'tax_value' => 0, 'status' => 'returned' ],
        ] );

        $report = app()->make( ReportService::class )->getGstReport(
            ns()->date->getNow()->startOfDay()->toDateTimeString(),
            ns()->date->getNow()->endOfDay()->toDateTimeString()
        );

        $row = $report[ 'sales' ]->firstWhere( 'hsn_code', $hsn );

        $this->assertNotNull( $row, 'The net-of-refund supply must remain on the report.' );
        $this->assertEquals( 3, (float) $row->quantity, 'The kept quantity of a partially refunded line must be reported.' );
        $this->assertEquals( 1500, (float) $row->taxable_value );
        $this->assertEquals( 270, (float) $row->tax_value );
    }

    public function test_gst_report_derives_rate_from_tax_when_rate_column_is_zero()
    {
        $this->attemptAuthenticate();

        $hsn = 'D' . rand( 10000000, 99999999 );

        /**
         * the POS flow does not persist the tax percentage on
         * order_products.rate (it stays 0); the report must derive the
         * effective rate from the tax and taxable values instead.
         */
        $this->createOrderWithProducts( [
            [ 'hsn_code' => $hsn, 'rate' => 0, 'quantity' => 2, 'total_price' => 2360, 'total_price_net' => 2000, 'tax_value' => 360 ],
        ] );

        $report = app()->make( ReportService::class )->getGstReport(
            ns()->date->getNow()->startOfDay()->toDateTimeString(),
            ns()->date->getNow()->endOfDay()->toDateTimeString()
        );

        $row = $report[ 'sales' ]->firstWhere( 'hsn_code', $hsn );

        $this->assertNotNull( $row );
        $this->assertEquals( 18.0, (float) $row->rate, 'The effective GST rate must be derived from tax / taxable value.' );
    }

    public function test_gst_report_page_renders()
    {
        $this->attemptAuthenticate();

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->get( '/dashboard/reports/gst' );

        $response->assertStatus( 200 );
        $response->assertSee( __( 'Outward Supplies (Sales)' ) );
    }

    public function test_gstin_can_be_saved_for_customers_and_providers()
    {
        $this->attemptAuthenticate();

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.providers', [
                'first_name' => 'GST Provider ' . uniqid(),
                'general' => [
                    'gstin' => '22AAAAA0000A1Z5',
                ],
            ] );

        $response->assertJson( [ 'status' => 'success' ] );

        $this->assertDatabaseHas( 'nexopos_providers', [
            'gstin' => '22AAAAA0000A1Z5',
        ] );
    }
}
