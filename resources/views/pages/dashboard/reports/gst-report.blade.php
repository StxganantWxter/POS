@extends( 'layout.dashboard' )

@section( 'layout.dashboard.body' )
<div class="flex-auto flex flex-col">
    @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
    <div class="flex-auto flex flex-col" id="dashboard-content">
        <div class="px-4">
            @include( '../common/dashboard/title' )
        </div>
        <div class="px-4 flex-auto flex flex-col">
            <div class="print:hidden mb-4">
                <form method="GET" action="{{ ns()->route( 'ns.dashboard.reports.gst' ) }}" class="flex flex-wrap items-end -mx-2">
                    <div class="px-2 mb-2">
                        <label class="block text-sm font-medium mb-1">{{ __( 'Start Date' ) }}</label>
                        <input type="datetime-local" name="startDate" value="{{ \Carbon\Carbon::parse( $startDate )->format( 'Y-m-d\TH:i' ) }}" class="border rounded p-2 bg-input-background border-input-edge"/>
                    </div>
                    <div class="px-2 mb-2">
                        <label class="block text-sm font-medium mb-1">{{ __( 'End Date' ) }}</label>
                        <input type="datetime-local" name="endDate" value="{{ \Carbon\Carbon::parse( $endDate )->format( 'Y-m-d\TH:i' ) }}" class="border rounded p-2 bg-input-background border-input-edge"/>
                    </div>
                    <div class="px-2 mb-2 flex">
                        <button type="submit" class="rounded shadow px-3 py-2 bg-info-primary text-white mr-2">{{ __( 'Load' ) }}</button>
                        <a href="javascript:window.print()" class="rounded shadow px-3 py-2 bg-box-background">{{ __( 'Print' ) }}</a>
                    </div>
                </form>
            </div>
            <div class="mb-2 text-sm text-secondary">
                <p><strong>{{ __( 'Store' ) }}:</strong> {{ ns()->option->get( 'ns_store_name' ) }} @if( ns()->option->get( 'ns_store_gstin' ) ) — <strong>{{ __( 'GSTIN' ) }}:</strong> {{ ns()->option->get( 'ns_store_gstin' ) }} @endif</p>
                <p><strong>{{ __( 'Period' ) }}:</strong> {{ ns()->date->getFormatted( $startDate ) }} — {{ ns()->date->getFormatted( $endDate ) }}</p>
            </div>
            <div class="mb-6">
                <h3 class="font-semibold text-lg mb-2">{{ __( 'Outward Supplies (Sales)' ) }}</h3>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-box-edge bg-box-background">
                        <thead>
                            <tr class="font-semibold border-b border-box-edge">
                                <td class="p-2 border border-box-edge">{{ __( 'HSN Code' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'Rate (%)' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'Quantity' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'Taxable Value' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'CGST' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'SGST' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'Total Tax' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'Total' ) }}</td>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse( $sales as $row )
                            <tr class="border-b border-box-edge">
                                <td class="p-2 border border-box-edge">{{ $row->hsn_code }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ $row->rate }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ $row->quantity }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $row->taxable_value ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $row->cgst ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $row->sgst ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $row->tax_value ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $row->total ) }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="p-2 text-center border border-box-edge">{{ __( 'No sales during this period.' ) }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold">
                                <td colspan="3" class="p-2 border border-box-edge">{{ __( 'Total' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $salesSummary[ 'taxable_value' ] ) }}</td>
                                <td colspan="2" class="p-2 border border-box-edge"></td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $salesSummary[ 'tax_value' ] ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $salesSummary[ 'total' ] ) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="mb-6">
                <h3 class="font-semibold text-lg mb-2">{{ __( 'Inward Supplies (Purchases)' ) }}</h3>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-box-edge bg-box-background">
                        <thead>
                            <tr class="font-semibold border-b border-box-edge">
                                <td class="p-2 border border-box-edge">{{ __( 'Tax Group' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'Quantity' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'Taxable Value' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'Input Tax' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ __( 'Total' ) }}</td>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse( $purchases as $row )
                            <tr class="border-b border-box-edge">
                                <td class="p-2 border border-box-edge">{{ $row->tax_group_name }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ $row->quantity }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $row->taxable_value ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $row->tax_value ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $row->total ) }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="p-2 text-center border border-box-edge">{{ __( 'No purchases during this period.' ) }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold">
                                <td colspan="2" class="p-2 border border-box-edge">{{ __( 'Total' ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $purchasesSummary[ 'taxable_value' ] ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $purchasesSummary[ 'tax_value' ] ) }}</td>
                                <td class="p-2 border border-box-edge text-right">{{ ns()->currency->define( $purchasesSummary[ 'total' ] ) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
