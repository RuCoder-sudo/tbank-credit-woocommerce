<?php
/**
 * Plugin Name: Т-Банк Рассрочка и Кредит для WooCommerce
 * Plugin URI: https://рукодер.рф/
 * Description: Профессиональный платёжный шлюз WooCommerce для рассрочки и кредита через Т-Рассрочка от Т-Банка. Реальная интеграция через REST API, кнопки на всех страницах, AJAX-калькулятор, email-уведомления, промо-коды, период охлаждения, тестовый режим.
 * Version: 3.0.0
 * Author: Сергей Солошенко (RuCoder)
 * Author URI: https://рукодер.рф/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tbank-credit-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.5
 * Stable tag: 3.0.0
 * Tags: tbank, t-bank, tinkoff, installment, credit, woocommerce, рассрочка, кредит
 *
 * Разработчик: Сергей Солошенко | РуКодер
 * Контакты:
 * - Телефон/WhatsApp: +7 (985) 985-53-97
 * - Email: support@рукодер.рф
 * - Telegram: @RussCoder
 * - Портфолио: https://рукодер.рф
 * - GitHub: https://github.com/RuCoder-sudo
 */

defined( 'ABSPATH' ) || exit;

define( 'TBANK_CREDIT_VERSION', '3.0.0' );
define( 'TBANK_CREDIT_PLUGIN_FILE', __FILE__ );
define( 'TBANK_CREDIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBANK_CREDIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Загружаем класс лицензии сразу (до WooCommerce)
require_once TBANK_CREDIT_PLUGIN_DIR . 'includes/class-tbank-credit-license.php';

/**
 * Check if WooCommerce is active.
 */
function tbank_credit_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'tbank_credit_woocommerce_missing_notice' );
        return false;
    }
    return true;
}

function tbank_credit_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>Т-Банк Рассрочка:</strong> Для работы плагина необходим активный плагин WooCommerce.</p></div>';
}

/**
 * Register license admin pages.
 */
add_action( 'admin_menu', array( 'TBank_Credit_License', 'register_admin_pages' ) );

/**
 * Show admin notice if plugin is not activated.
 */
add_action( 'admin_notices', 'tbank_credit_license_notice' );
function tbank_credit_license_notice() {
    if ( TBank_Credit_License::is_active() ) {
        return;
    }
    $screen = get_current_screen();
    if ( $screen && strpos( $screen->id, TBank_Credit_License::ADMIN_PAGE ) !== false ) {
        return;
    }
    if ( $screen && strpos( $screen->id, 'wc-settings' ) !== false ) {
        return;
    }
    $url = admin_url( 'admin.php?page=' . TBank_Credit_License::ADMIN_PAGE );
    echo '<div class="notice notice-warning">';
    echo '<p>🔒 <strong>Т-Банк Рассрочка и Кредит:</strong> Плагин не активирован. Настройки заблокированы. ';
    echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="margin-left:8px;">Активировать плагин</a></p>';
    echo '</div>';
}

/**
 * Initialize the plugin.
 */
function tbank_credit_init() {
    if ( ! tbank_credit_check_woocommerce() ) {
        return;
    }

    require_once TBANK_CREDIT_PLUGIN_DIR . 'includes/class-tbank-credit-gateway.php';
    require_once TBANK_CREDIT_PLUGIN_DIR . 'includes/class-tbank-credit-webhook.php';
    require_once TBANK_CREDIT_PLUGIN_DIR . 'includes/class-tbank-credit-product.php';

    add_filter( 'woocommerce_payment_gateways', 'tbank_credit_add_gateway' );

    if ( is_admin() ) {
        add_action( 'add_meta_boxes', 'tbank_credit_add_product_metabox' );
        add_action( 'save_post_product', 'tbank_credit_save_product_metabox' );
        add_action( 'wp_ajax_tbank_credit_test_connection', 'tbank_credit_ajax_test_connection' );
    }

    add_action( 'wp_ajax_tbank_credit_calculate', 'tbank_credit_ajax_calculate' );
    add_action( 'wp_ajax_nopriv_tbank_credit_calculate', 'tbank_credit_ajax_calculate' );
}
add_action( 'plugins_loaded', 'tbank_credit_init', 0 );

/**
 * Add the gateway to WooCommerce.
 */
function tbank_credit_add_gateway( $gateways ) {
    $gateways[] = 'WC_TBank_Credit_Gateway';
    return $gateways;
}

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Load assets on frontend.
 */
function tbank_credit_enqueue_scripts() {
    if ( is_checkout() || is_product() || is_shop() || is_product_category() || is_cart() ) {
        wp_enqueue_style(
            'tbank-credit-style',
            TBANK_CREDIT_PLUGIN_URL . 'assets/css/tbank-credit.css',
            array(),
            TBANK_CREDIT_VERSION
        );
        wp_enqueue_script(
            'tbank-credit-script',
            TBANK_CREDIT_PLUGIN_URL . 'assets/js/tbank-credit.js',
            array( 'jquery' ),
            TBANK_CREDIT_VERSION,
            true
        );

        $gateway    = tbank_credit_get_gateway_instance();
        $periods    = '';
        $min_amount = 3000;
        $promo_list = array();

        if ( $gateway ) {
            $periods    = $gateway->get_option( 'installment_periods', '' );
            $min_amount = (float) $gateway->get_option( 'min_amount', 3000 );
            $promo_list = $gateway->get_promo_codes_array();
        }

        wp_localize_script( 'tbank-credit-script', 'tbankCreditData', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'tbank_credit_nonce' ),
            'minAmount' => $min_amount,
            'periods'   => $periods,
            'promos'    => $promo_list,
            'i18n'      => array(
                'from'     => 'от',
                'perMonth' => '₽/мес',
            ),
        ) );
    }
}
add_action( 'wp_enqueue_scripts', 'tbank_credit_enqueue_scripts' );

/**
 * Add settings link on plugin page.
 */
function tbank_credit_plugin_links( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=tbank_credit' ) . '">' . __( 'Настройки', 'tbank-credit-woocommerce' ) . '</a>';
    $support_link  = '<a href="https://рукодер.рф/" target="_blank">Поддержка</a>';
    array_unshift( $links, $settings_link, $support_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'tbank_credit_plugin_links' );

/**
 * Helper: get gateway instance from WooCommerce.
 *
 * @return WC_TBank_Credit_Gateway|null
 */
function tbank_credit_get_gateway_instance() {
    if ( ! function_exists( 'WC' ) ) {
        return null;
    }
    $gateways = WC()->payment_gateways();
    if ( ! $gateways ) {
        return null;
    }
    $all = $gateways->payment_gateways();
    return isset( $all['tbank_credit'] ) ? $all['tbank_credit'] : null;
}

/**
 * Per-product metabox: disable T-Bank credit for this product.
 */
function tbank_credit_add_product_metabox() {
    add_meta_box(
        'tbank_credit_product',
        'Т-Банк Рассрочка',
        'tbank_credit_render_product_metabox',
        'product',
        'side',
        'default'
    );
}

function tbank_credit_render_product_metabox( $post ) {
    wp_nonce_field( 'tbank_credit_product_nonce', '_tbank_credit_nonce' );
    $disabled = get_post_meta( $post->ID, '_tbank_credit_disabled', true );
    echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">';
    echo '<input type="checkbox" name="tbank_credit_disabled" value="1" ' . checked( $disabled, '1', false ) . '>';
    echo '<span>Отключить кнопку рассрочки/кредита для этого товара</span>';
    echo '</label>';
}

function tbank_credit_save_product_metabox( $post_id ) {
    if ( ! isset( $_POST['_tbank_credit_nonce'] ) || ! wp_verify_nonce( $_POST['_tbank_credit_nonce'], 'tbank_credit_product_nonce' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    $disabled = isset( $_POST['tbank_credit_disabled'] ) ? '1' : '0';
    update_post_meta( $post_id, '_tbank_credit_disabled', $disabled );
}

/**
 * AJAX: installment calculator.
 */
function tbank_credit_ajax_calculate() {
    check_ajax_referer( 'tbank_credit_nonce', 'nonce' );

    $amount  = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
    $months  = isset( $_POST['months'] ) ? max( 1, intval( $_POST['months'] ) ) : 6;
    $monthly = $amount > 0 ? ceil( $amount / $months ) : 0;

    wp_send_json_success( array(
        'monthly' => $monthly,
        'amount'  => $amount,
        'months'  => $months,
        'text'    => 'от ' . number_format( $monthly, 0, '.', ' ' ) . ' ₽/мес',
    ) );
}

/**
 * AJAX: test connection — реальная проверка через API Т-Банка.
 *
 * Делает тестовый POST-запрос на /create-demo и анализирует ответ.
 * Если Т-Банк вернёт ссылку — реквизиты верные.
 * Если ошибку "Не найдено настроек..." — реквизиты не существуют.
 */
function tbank_credit_ajax_test_connection() {
    check_ajax_referer( 'tbank_credit_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => 'Доступ запрещён.' ) );
    }

    $shop_id     = sanitize_text_field( $_POST['shop_id'] ?? '' );
    $showcase_id = sanitize_text_field( $_POST['showcase_id'] ?? '' );

    // ── Шаг 1: Проверка заполненности полей ──────────────────────────────────
    $errors = array();
    if ( empty( $shop_id ) ) {
        $errors[] = 'не заполнен Shop ID';
    }
    if ( empty( $showcase_id ) ) {
        $errors[] = 'не заполнен Showcase ID';
    }
    if ( ! empty( $errors ) ) {
        wp_send_json_error( array( 'message' => '❌ Заполните поля: ' . implode( ', ', $errors ) . '.' ) );
    }

    // ── Шаг 2: Проверка формата ───────────────────────────────────────────────
    // Принимаем два формата:
    // Числовой: только цифры (например: 1234567)
    // UUID: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx (например: 820876e3-099d-499e-a3e3-688e83a2efad)
    $uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    $shop_id_valid = ctype_digit( $shop_id ) || preg_match( $uuid_pattern, $shop_id );
    $showcase_id_valid = preg_match( '/^[A-Za-z]{2}\d{8,}$/', $showcase_id ) || preg_match( $uuid_pattern, $showcase_id );

    $format_errors = array();

    if ( ! $shop_id_valid ) {
        $format_errors[] = 'Shop ID указан в неверном формате. Допустимы: числовой (например: 1234567) или UUID (например: 820876e3-099d-499e-a3e3-688e83a2efad)';
    }

    if ( ! $showcase_id_valid ) {
        $format_errors[] = 'Showcase ID указан в неверном формате. Допустимы: буквенно-цифровой (например: MB0001234567) или UUID (например: cf3a5035-010d-4ea8-9915-6882bf8c9bed)';
    }

    if ( ! empty( $format_errors ) ) {
        wp_send_json_error( array(
            'message' => '❌ Ошибка формата реквизитов:<br>• ' . implode( '<br>• ', $format_errors ) .
                         '<br><br>Точные значения берите из Личного кабинета Т-Банка → «Кредитование в магазинах».',
        ) );
    }

    // ── Шаг 3: Реальная проверка через API Т-Банка ────────────────────────────
    // Делаем демо-запрос на создание тестовой заявки.
    // Т-Банк вернёт ошибку если shopId+showcaseId не существуют в системе.
    $api_url = 'https://forma.tinkoff.ru/api/partners/v2/orders/create-demo';

    $body = wp_json_encode( array(
        'shopId'          => $shop_id,
        'showcaseId'      => $showcase_id,
        'sum'             => 10000,
        'integrationType' => 'plugin',
        'orderNumber'     => 'test-' . time(),
        'items'           => array(
            array(
                'name'     => 'Тестовый товар',
                'price'    => 10000,
                'quantity' => 1,
            ),
        ),
        'failURL'         => home_url( '/' ),
        'successURL'      => home_url( '/' ),
        'returnURL'       => home_url( '/' ),
        'values'          => array(
            'contact' => array(
                'fio'         => array( 'lastName' => 'Тест', 'firstName' => 'Тест' ),
                'mobilePhone' => '79001234567',
                'email'       => get_option( 'admin_email' ),
            ),
        ),
    ) );

    $response = wp_remote_post( $api_url, array(
        'timeout'    => 12,
        'headers'    => array(
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'integrationType'=> 'plugin',
        ),
        'body'       => $body,
        'user-agent' => 'TBank-WooCommerce-Plugin/' . TBANK_CREDIT_VERSION,
    ) );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( array(
            'message' => '❌ Сервер Т-Банка недоступен: ' . $response->get_error_message() .
                         '<br>Проверьте интернет-соединение хостинга.',
        ) );
    }

    $http_code    = (int) wp_remote_retrieve_response_code( $response );
    $body_raw     = wp_remote_retrieve_body( $response );
    $decoded      = json_decode( $body_raw, true );

    // Нет связи с сервером вообще
    if ( $http_code === 0 ) {
        wp_send_json_error( array( 'message' => '❌ Нет ответа от сервера Т-Банка. Проверьте доступность интернета на хостинге.' ) );
    }

    // Т-Банк вернул 200 с ссылкой — реквизиты верные!
    if ( $http_code === 200 && ! empty( $decoded['link'] ) ) {
        wp_send_json_success( array(
            'message' => '<span style="color:#1a6b2b;font-weight:600;">✅ Реквизиты верны! Т-Банк подтвердил Shop ID и Showcase ID.</span>' .
                         '<br><span style="color:#555;font-size:12px;">Сервер вернул тестовую ссылку — это значит ваш магазин зарегистрирован в системе Т-Банка.</span>',
        ) );
    }

    // Анализируем ошибки от Т-Банка
    $api_errors = array();
    if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
        $api_errors = $decoded['errors'];
    } elseif ( ! empty( $decoded['message'] ) ) {
        $api_errors[] = $decoded['message'];
    }

    if ( ! empty( $api_errors ) ) {
        $err_text = implode( '<br>• ', array_map( 'esc_html', $api_errors ) );

        // Конкретная ошибка "Не найдено настроек" — реквизиты неверные
        foreach ( $api_errors as $err ) {
            if ( stripos( $err, 'Не найдено настроек' ) !== false || stripos( $err, 'shopId' ) !== false ) {
                wp_send_json_error( array(
                    'message' => '❌ <strong>Реквизиты не найдены в системе Т-Банка.</strong>' .
                                 '<br>Ошибка: ' . esc_html( $err ) .
                                 '<br><br>Проверьте Shop ID и Showcase ID в <a href="https://business.tbank.ru/posbroker" target="_blank">Личном кабинете Т-Банка</a>.',
                ) );
            }
        }

        // Иная ошибка
        wp_send_json_error( array(
            'message' => '⚠️ Т-Банк вернул ошибку (HTTP ' . $http_code . '):<br>• ' . $err_text,
        ) );
    }

    // HTTP 4xx — обычно означает проблемы с реквизитами
    if ( $http_code >= 400 && $http_code < 500 ) {
        wp_send_json_error( array(
            'message' => '❌ Т-Банк отклонил запрос (HTTP ' . $http_code . '). Возможно, реквизиты неверны или магазин не активирован.' .
                         ( $body_raw ? '<br><code>' . esc_html( substr( $body_raw, 0, 200 ) ) . '</code>' : '' ),
        ) );
    }

    // Любой другой ответ — сервер доступен, но реквизиты не подтверждены
    wp_send_json_success( array(
        'message' => '<span style="color:#b45309;font-weight:600;">⚠️ Сервер Т-Банка доступен (HTTP ' . $http_code . '), но статус реквизитов неясен.</span>' .
                     '<br>Рекомендуем оформить тестовый заказ для проверки.',
    ) );
}
