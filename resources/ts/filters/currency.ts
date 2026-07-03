import NumeralJS from "numeral";
import Vue from 'vue';
import currency from 'currency.js';

declare const ns;
declare const window;

const precision     =   ( new Array( parseInt( ns.currency.ns_currency_precision ) ) ).fill('').map( _ => 0 ).join('');

/**
 * Format a raw number using the indian numbering system:
 * the last three digits are grouped together, every other
 * group counts two digits (eg: 12,34,567.89).
 * @param value the value to format
 * @returns string
 */
const formatIndianNumber    =   ( value ) => {
    const amount        =   Number( value ) || 0;
    const digits        =   parseInt( ns.currency.ns_currency_precision );
    const fixed         =   Math.abs( amount ).toFixed( digits );
    const [ integers, decimals ]    =   fixed.split( '.' );

    let grouped     =   integers;

    if ( integers.length > 3 ) {
        const lastThree     =   integers.slice( -3 );
        const remaining     =   integers.slice( 0, -3 )
            .replace( /\B(?=(\d{2})+(?!\d))/g, ns.currency.ns_currency_thousand_separator );

        grouped     =   remaining + ns.currency.ns_currency_thousand_separator + lastThree;
    }

    return ( amount < 0 ? '-' : '' ) + grouped + ( decimals !== undefined ? ns.currency.ns_currency_decimal_separator + decimals : '' );
}

/**
 * Convert a number into a currency format.
 * @param value the value to convert
 * @param format amount format
 * @param locale locale
 * @returns string
 */
const nsCurrency    =   ( value, format = 'full', locale = 'en' ) => {
    let numeralFormat, currencySymbol;

    switch( ns.currency.ns_currency_prefered ) {
        case 'iso' :
            currencySymbol  =   ns.currency.ns_currency_iso;
        break;
        case 'symbol' :
            currencySymbol  =   ns.currency.ns_currency_symbol;
        break;
    }

    let newValue;

    if ( format === 'full' ) {
        if ( ns.currency.ns_currency_numbering === 'indian' ) {
            newValue    =   formatIndianNumber( value );
        } else {
            const config            =   {
                decimal: ns.currency.ns_currency_decimal_separator,
                separator: ns.currency.ns_currency_thousand_separator,
                precision : parseInt( ns.currency.ns_currency_precision ),
                symbol: ''
            };

            newValue    =   currency( value, config ).format();
        }
    } else {
        newValue    =   NumeralJS( value ).format( '0.0a' );
    }

    return `${ns.currency.ns_currency_position === 'before' ? currencySymbol : '' }${ newValue }${ns.currency.ns_currency_position === 'after' ? currencySymbol : '' }`;

}

const nsRawCurrency     =   ( value ) => {
    const numeralFormat = `0.000000000`;
    return parseFloat( NumeralJS( value ).format( numeralFormat ) );
}

/**
 * Will abbreviate an amount to return a short form.
 * @param value amount to abbreviate
 * @returns string
 */
const nsNumberAbbreviate    =   ( value ) => {
    return NumeralJS( value ).format( '0a' );
}

export { nsCurrency, nsRawCurrency, nsNumberAbbreviate };