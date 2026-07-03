<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class SecurityControlsTest extends TestCase
{
    use WithAuthentication;

    private function roleHasPermission( string $roleNamespace, string $permission ): bool
    {
        $role = Role::where( 'namespace', $roleNamespace )->first();

        return $role !== null && $role->permissions()->where( 'namespace', $permission )->exists();
    }

    public function test_stock_adjustment_approval_enforces_segregation_of_duties()
    {
        $this->attemptAuthenticate();

        $permission = 'nexopos.approve.products-adjustments';

        $this->assertDatabaseHas( 'nexopos_permissions', [ 'namespace' => $permission ] );

        $this->assertTrue( $this->roleHasPermission( Role::ADMIN, $permission ), 'Admins must be able to approve adjustments.' );
        $this->assertTrue( $this->roleHasPermission( Role::STOREADMIN, $permission ), 'Store administrators must be able to approve adjustments.' );

        /**
         * a cashier can draft an adjustment but must never be able to
         * approve/execute one, so shrinkage cannot be self-authorised.
         */
        $this->assertFalse( $this->roleHasPermission( Role::STORECASHIER, $permission ), 'Cashiers must not be able to approve adjustments.' );
    }

    public function test_gst_report_is_not_exposed_to_cashiers()
    {
        $this->attemptAuthenticate();

        $this->assertTrue( $this->roleHasPermission( Role::ADMIN, 'nexopos.reports.gst' ) );
        $this->assertFalse( $this->roleHasPermission( Role::STORECASHIER, 'nexopos.reports.gst' ), 'Cashiers must not access the GST report.' );
    }
}
