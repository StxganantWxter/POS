<?php

namespace App\Console\Commands;

use App\Models\PaymentType;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class LiquorStoreSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ns:liquor-setup {--without-categories : skip creating the default liquor categories}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seeds Indian liquor store defaults: GST tax groups, payment types (UPI, Card, Cheque), liquor packaging units, product categories and rupee options. Safe to run multiple times.';

    public function handle(): int
    {
        $author = $this->getAdministratorId();

        $this->seedTaxGroups( $author );
        $this->seedPaymentTypes( $author );
        $this->seedUnitGroups( $author );

        if ( ! $this->option( 'without-categories' ) ) {
            $this->seedCategories( $author );
        }

        $this->seedOptions();

        $this->info( 'The liquor store defaults have been installed.' );

        return self::SUCCESS;
    }

    private function getAdministratorId(): int
    {
        $admin = User::whereHas( 'roles', function ( $query ) {
            $query->where( 'namespace', Role::ADMIN );
        } )->first() ?: User::first();

        return $admin?->id ?? 1;
    }

    private function seedTaxGroups( int $author ): void
    {
        $rates = [ 0, 5, 12, 18, 28 ];

        foreach ( $rates as $rate ) {
            $groupName = sprintf( 'GST %s%%', $rate );

            $group = TaxGroup::where( 'name', $groupName )->first() ?: new TaxGroup;
            $group->name = $groupName;

            if ( ! $group->exists ) {
                $group->description = sprintf( 'GST %s%% split into CGST and SGST for intra-state supplies.', $rate );
                $group->author_id = $author;
                $group->save();

                foreach ( [ 'CGST', 'SGST' ] as $component ) {
                    $tax = new Tax;
                    $tax->name = sprintf( '%s %s%%', $component, $rate / 2 );
                    $tax->rate = $rate / 2;
                    $tax->tax_group_id = $group->id;
                    $tax->description = sprintf( '%s component of %s.', $component, $groupName );
                    $tax->author_id = $author;
                    $tax->save();
                }

                $this->line( sprintf( 'Created tax group: %s', $groupName ) );
            }

            $igstName = sprintf( 'IGST %s%%', $rate );

            $igstGroup = TaxGroup::where( 'name', $igstName )->first() ?: new TaxGroup;
            $igstGroup->name = $igstName;

            if ( ! $igstGroup->exists ) {
                $igstGroup->description = sprintf( 'IGST %s%% for inter-state supplies.', $rate );
                $igstGroup->author_id = $author;
                $igstGroup->save();

                $tax = new Tax;
                $tax->name = $igstName;
                $tax->rate = $rate;
                $tax->tax_group_id = $igstGroup->id;
                $tax->description = sprintf( 'Integrated GST at %s%%.', $rate );
                $tax->author_id = $author;
                $tax->save();

                $this->line( sprintf( 'Created tax group: %s', $igstName ) );
            }
        }
    }

    private function seedPaymentTypes( int $author ): void
    {
        $paymentTypes = [
            [ 'label' => __( 'UPI' ), 'identifier' => 'upi-payment', 'priority' => 1 ],
            [ 'label' => __( 'Card' ), 'identifier' => 'card-payment', 'priority' => 2 ],
            [ 'label' => __( 'Cheque' ), 'identifier' => 'cheque-payment', 'priority' => 3 ],
        ];

        foreach ( $paymentTypes as $fields ) {
            $paymentType = PaymentType::where( 'identifier', $fields[ 'identifier' ] )->first() ?: new PaymentType;
            $paymentType->identifier = $fields[ 'identifier' ];

            if ( ! $paymentType->exists ) {
                $paymentType->label = $fields[ 'label' ];
                $paymentType->priority = $fields[ 'priority' ];
                $paymentType->active = true;
                $paymentType->author_id = $author;
                $paymentType->save();

                $this->line( sprintf( 'Created payment type: %s', $fields[ 'label' ] ) );
            }
        }
    }

    private function seedUnitGroups( int $author ): void
    {
        $group = UnitGroup::where( 'name', 'Liquor Packaging' )->first() ?: new UnitGroup;
        $group->name = 'Liquor Packaging';

        if ( ! $group->exists ) {
            $group->description = 'Bottle and case units used for liquor sales and procurement.';
            $group->author_id = $author;
            $group->save();

            $units = [
                [ 'name' => 'Bottle', 'value' => 1, 'base_unit' => true ],
                [ 'name' => 'Case of 12', 'value' => 12, 'base_unit' => false ],
                [ 'name' => 'Case of 24', 'value' => 24, 'base_unit' => false ],
                [ 'name' => 'Case of 48', 'value' => 48, 'base_unit' => false ],
            ];

            foreach ( $units as $fields ) {
                $unit = new Unit;
                $unit->name = $fields[ 'name' ];
                $unit->identifier = Str::slug( $fields[ 'name' ] );
                $unit->group_id = $group->id;
                $unit->base_unit = $fields[ 'base_unit' ];
                $unit->value = $fields[ 'value' ];
                $unit->author_id = $author;
                $unit->save();
            }

            $this->line( 'Created unit group: Liquor Packaging (Bottle, Case of 12/24/48)' );
        }
    }

    private function seedCategories( int $author ): void
    {
        $categories = [ 'Whisky', 'Beer', 'Wine', 'Rum', 'Vodka', 'Brandy', 'Gin', 'Country Liquor', 'Ready To Drink' ];

        foreach ( $categories as $name ) {
            $category = ProductCategory::where( 'name', $name )->first() ?: new ProductCategory;
            $category->name = $name;

            if ( ! $category->exists ) {
                $category->displays_on_pos = true;
                $category->author_id = $author;
                $category->save();

                $this->line( sprintf( 'Created category: %s', $name ) );
            }
        }
    }

    private function seedOptions(): void
    {
        $options = [
            'ns_currency_symbol' => '₹',
            'ns_currency_iso' => 'INR',
            'ns_currency_prefered' => 'symbol',
            'ns_currency_position' => 'before',
            'ns_currency_thousand_separator' => ',',
            'ns_currency_decimal_separator' => '.',
            'ns_currency_numbering' => 'indian',
            'ns_currency_precision' => 2,
        ];

        foreach ( $options as $key => $value ) {
            ns()->option->set( $key, $value );
        }

        $this->line( 'Applied Indian rupee currency options.' );
    }
}
