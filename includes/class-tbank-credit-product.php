<?php
defined( 'ABSPATH' ) || exit;

/**
 * WC_TBank_Credit_Product — кнопка кредита/рассрочки в каталоге товаров.
 *
 * @author  Сергей Солошенко (RuCoder) — https://рукодер.рф/
 * @version 2.0.0
 */
class WC_TBank_Credit_Product {

    /** @var WC_TBank_Credit_Gateway */
    private $gateway;

    public function __construct( WC_TBank_Credit_Gateway $gateway ) {
        $this->gateway = $gateway;

        if ( 'yes' === $gateway->get_option( 'show_on_catalog', 'no' ) ) {
            add_action( 'woocommerce_after_shop_loop_item', array( $this, 'catalog_credit_button' ), 15 );
        }
    }

    /**
     * Output credit button on catalog pages (shop, category, tag, search).
     */
    public function catalog_credit_button() {
        global $product;
        if ( ! $product || 'yes' !== $this->gateway->enabled ) {
            return;
        }

        if ( $this->gateway->is_product_excluded( $product ) ) {
            return;
        }

        $price = $product->is_type( 'variable' )
            ? (float) $product->get_variation_price( 'min' )
            : (float) $product->get_price();

        if ( $price < $this->gateway->min_amount ) {
            return;
        }
        if ( $this->gateway->max_amount > 0 && $price > $this->gateway->max_amount ) {
            return;
        }

        echo '<div class="tbank-credit-catalog-btn-wrap">';
        $this->gateway->render_credit_button( $price );
        echo '</div>';
    }
}
