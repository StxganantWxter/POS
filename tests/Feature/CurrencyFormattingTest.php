<?php

namespace Tests\Feature;

use App\Services\CurrencyService;
use Tests\TestCase;

class CurrencyFormattingTest extends TestCase
{
    private function makeCurrency( float|int $value, string $numberingSystem, int $precision = 2 ): CurrencyService
    {
        return new CurrencyService( $value, [
            'currency_iso' => 'INR',
            'currency_symbol' => '₹',
            'currency_position' => 'before',
            'decimal_precision' => $precision,
            'decimal_separator' => '.',
            'thousand_separator' => ',',
            'prefered_currency' => 'symbol',
            'numbering_system' => $numberingSystem,
        ] );
    }

    public function test_indian_numbering_groups_lakh_and_crore()
    {
        $this->assertEquals( '₹ 1,25,000.00 ', $this->makeCurrency( 125000, CurrencyService::NUMBERING_INDIAN )->format() );
        $this->assertEquals( '₹ 12,34,567.89 ', $this->makeCurrency( 1234567.89, CurrencyService::NUMBERING_INDIAN )->format() );
        $this->assertEquals( '₹ 1,23,45,678.90 ', $this->makeCurrency( 12345678.9, CurrencyService::NUMBERING_INDIAN )->format() );
        $this->assertEquals( '₹ 999.99 ', $this->makeCurrency( 999.99, CurrencyService::NUMBERING_INDIAN )->format() );
        $this->assertEquals( '₹ 1,000.00 ', $this->makeCurrency( 1000, CurrencyService::NUMBERING_INDIAN )->format() );
        $this->assertEquals( '₹ 0.00 ', $this->makeCurrency( 0, CurrencyService::NUMBERING_INDIAN )->format() );
    }

    public function test_indian_numbering_handles_negative_amounts()
    {
        $this->assertEquals( '₹ -1,25,000.00 ', $this->makeCurrency( -125000, CurrencyService::NUMBERING_INDIAN )->format() );
    }

    public function test_indian_numbering_without_decimals()
    {
        $this->assertEquals( '₹ 12,34,567 ', $this->makeCurrency( 1234567, CurrencyService::NUMBERING_INDIAN, 0 )->format() );
    }

    public function test_international_numbering_remains_unchanged()
    {
        $this->assertEquals( '₹ 125,000.00 ', $this->makeCurrency( 125000, CurrencyService::NUMBERING_INTERNATIONAL )->format() );
        $this->assertEquals( '₹ 12,345,678.90 ', $this->makeCurrency( 12345678.9, CurrencyService::NUMBERING_INTERNATIONAL )->format() );
    }

    public function test_indian_numbering_preserves_raw_values()
    {
        $currency = $this->makeCurrency( 125000, CurrencyService::NUMBERING_INDIAN );

        $this->assertEquals( 125000.0, $currency->get(), 'Formatting should never affect the raw value used by calculations.' );
    }

    public function test_numbering_option_reaches_the_currency_service()
    {
        $impactedOptions = [
            'ns_currency_numbering' => CurrencyService::NUMBERING_INDIAN,
            'ns_currency_thousand_separator' => ',',
            'ns_currency_decimal_separator' => '.',
            'ns_currency_precision' => 2,
        ];

        $previousValues = collect( $impactedOptions )
            ->mapWithKeys( fn( $value, $option ) => [ $option => ns()->option->get( $option ) ] );

        try {
            collect( $impactedOptions )->each( fn( $value, $option ) => ns()->option->set( $option, $value ) );

            $formatted = app()->make( CurrencyService::class )
                ->value( 125000 )
                ->format();

            $this->assertStringContainsString( '1,25,000.00', $formatted, 'The saved options should drive the resolved currency service.' );
        } finally {
            $previousValues->each( fn( $value, $option ) => $value === null
                ? ns()->option->delete( $option )
                : ns()->option->set( $option, $value ) );
        }
    }
}
