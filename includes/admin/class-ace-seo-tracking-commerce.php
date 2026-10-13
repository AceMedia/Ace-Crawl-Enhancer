<?php
defined( 'ABSPATH' ) || exit;

/** Read orders through WooCommerce's storage API (legacy tables and HPOS). */
class AceSeoTrackingCommerce {
    const CACHE = 'ace_seo_tracking_commerce';

    public static function series( $dates, $force = false ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array( 'enabled' => false, 'ok' => false, 'days' => array() );
        }
        $currency = get_woocommerce_currency();
        $out = array( 'enabled' => true, 'ok' => false, 'days' => array(), 'currency' => $currency, 'error' => '', 'skipped_currency' => 0 );
        if ( ! $dates ) { return $out; }
        $key = md5( wp_json_encode( array( $dates, $currency, wp_timezone()->getName() ) ) );
        $cached = get_transient( self::CACHE );
        if ( ! $force && is_array( $cached ) && ( $cached['key'] ?? '' ) === $key ) { return $cached; }
        foreach ( $dates as $date ) { $out['days'][ $date ] = array( 'wc_orders' => 0, 'wc_value' => 0.0 ); }
        $start = new DateTimeImmutable( reset( $dates ) . ' 00:00:00', wp_timezone() );
        $end = new DateTimeImmutable( end( $dates ) . ' 23:59:59', wp_timezone() );
        try {
            $page = 1;
            do {
                $result = wc_get_orders( array(
                    'type' => 'shop_order', 'status' => array_unique( array_merge( wc_get_is_paid_statuses(), array( 'refunded' ) ) ),
                    'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
                    'limit' => 100, 'page' => $page, 'paginate' => true, 'orderby' => 'ID', 'order' => 'ASC',
                ) );
                if ( ! is_object( $result ) || ! isset( $result->orders, $result->max_num_pages ) ) {
                    throw new RuntimeException( 'WooCommerce did not return an order report.' );
                }
                if ( $result->max_num_pages > 200 ) {
                    throw new RuntimeException( 'This period exceeds 20,000 orders. Use WooCommerce Analytics for the full report.' );
                }
                foreach ( $result->orders as $order ) {
                    if ( $order->get_currency() !== $currency ) { ++$out['skipped_currency']; continue; }
                    $created = $order->get_date_created();
                    if ( ! $created ) { continue; }
                    $date = wp_date( 'Y-m-d', $created->getTimestamp(), wp_timezone() );
                    if ( isset( $out['days'][ $date ] ) ) {
                        ++$out['days'][ $date ]['wc_orders'];
                        $out['days'][ $date ]['wc_value'] += (float) $order->get_total() - (float) $order->get_total_refunded();
                    }
                }
                ++$page;
            } while ( $page <= $result->max_num_pages );
            foreach ( $out['days'] as &$day ) { $day['wc_value'] = round( $day['wc_value'], wc_get_price_decimals() ); }
            unset( $day );
            $out['ok'] = true;
        } catch ( Throwable $e ) {
            $out['error'] = $e->getMessage();
            $out['days'] = array(); // Never chart a partial report as complete.
        }
        $out['key'] = $key;
        $out['at'] = time();
        set_transient( self::CACHE, $out, $out['ok'] ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
        return $out;
    }
}
