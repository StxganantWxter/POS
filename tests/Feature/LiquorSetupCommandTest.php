<?php

namespace Tests\Feature;

use App\Models\PaymentType;
use App\Models\ProductCategory;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\UnitGroup;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class LiquorSetupCommandTest extends TestCase
{
    use WithAuthentication;

    public function test_liquor_setup_seeds_india_defaults_idempotently()
    {
        $this->attemptAuthenticate();

        Artisan::call( 'ns:liquor-setup' );

        /**
         * running twice must not duplicate anything.
         */
        Artisan::call( 'ns:liquor-setup' );

        foreach ( [ 0, 5, 12, 18, 28 ] as $rate ) {
            $this->assertEquals( 1, TaxGroup::where( 'name', sprintf( 'GST %s%%', $rate ) )->count() );
            $this->assertEquals( 1, TaxGroup::where( 'name', sprintf( 'IGST %s%%', $rate ) )->count() );
        }

        $gst18 = TaxGroup::where( 'name', 'GST 18%' )->first();
        $taxes = Tax::where( 'tax_group_id', $gst18->id )->get();

        $this->assertCount( 2, $taxes, 'GST groups should hold a CGST and an SGST component.' );
        $this->assertEquals( 18.0, (float) $taxes->sum( 'rate' ), 'The CGST + SGST components should sum to the group rate.' );

        foreach ( [ 'upi-payment', 'card-payment', 'cheque-payment' ] as $identifier ) {
            $this->assertEquals( 1, PaymentType::where( 'identifier', $identifier )->count() );
        }

        $this->assertEquals( 1, UnitGroup::where( 'name', 'Liquor Packaging' )->count() );

        $group = UnitGroup::where( 'name', 'Liquor Packaging' )->first();

        $this->assertCount( 4, $group->units, 'The liquor packaging group should hold 4 units.' );
        $this->assertEquals( 1, $group->units->where( 'base_unit', true )->count(), 'A single base unit should exist.' );

        $this->assertEquals( 1, ProductCategory::where( 'name', 'Whisky' )->count() );

        $this->assertEquals( '₹', ns()->option->get( 'ns_currency_symbol' ) );
        $this->assertEquals( 'indian', ns()->option->get( 'ns_currency_numbering' ) );
    }
}
