<?php
defined( 'ABSPATH' ) || exit;

/**
 * WC_TBank_Credit_Gateway — платёжный шлюз «Т-Банк Рассрочка и Кредит».
 *
 * Использует REST API Т-Банка:
 *   POST https://forma.tinkoff.ru/api/partners/v2/orders/create      (боевой)
 *   POST https://forma.tinkoff.ru/api/partners/v2/orders/create-demo (тест)
 *   GET  https://forma.tinkoff.ru/api/partners/v2/orders/{id}/info   (статус)
 *
 * @author  Сергей Солошенко (RuCoder) — https://рукодер.рф/
 * @version 3.0.0
 */
class WC_TBank_Credit_Gateway extends WC_Payment_Gateway {

    const API_BASE = 'https://forma.tinkoff.ru/api/partners/v2/orders';

    /** @var string Inline SVG иконка Т-Банка */
    const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="22" height="22" aria-hidden="true"><rect width="32" height="32" rx="6" fill="#1a1a1a"/><text x="16" y="23" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="900" font-size="20" fill="#FFDD2D">Т</text></svg>';

    public function __construct() {
        $this->id                 = 'tbank_credit';
        $this->icon               = '';
        $this->has_fields         = true; // needed for payment_fields()
        $this->method_title       = __( 'Т-Банк Рассрочка и Кредит', 'tbank-credit-woocommerce' );
        $this->method_description = $this->get_method_description();
        $this->supports           = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled          = $this->get_option( 'enabled' );
        $this->title            = $this->get_option( 'title', 'Рассрочка или кредит от Т-Банка' );
        $this->description      = $this->get_option( 'description', 'Оформите покупку в рассрочку или кредит через Т-Банк.' );
        $this->shop_id          = $this->get_option( 'shop_id' );
        $this->showcase_id      = $this->get_option( 'showcase_id' );
        $this->api_password     = $this->get_option( 'api_password' );
        $this->test_mode        = 'yes' === $this->get_option( 'test_mode', 'yes' );
        $this->enable_cooling   = 'yes' === $this->get_option( 'enable_cooling', 'no' );
        $this->min_amount       = (float) $this->get_option( 'min_amount', 3000 );
        $this->max_amount       = (float) $this->get_option( 'max_amount', 0 );
        $this->btn_text_credit  = $this->get_option( 'btn_text_credit', 'Купить в кредит' );
        $this->btn_text_install = $this->get_option( 'btn_text_install', 'Купить в рассрочку' );
        $this->btn_color        = $this->get_option( 'btn_color', '#FFDD2D' );
        $this->btn_radius       = $this->get_option( 'btn_radius', '8' );
        $this->open_new_window  = 'yes' === $this->get_option( 'open_new_window', 'no' );
        $this->show_calculator  = 'yes' === $this->get_option( 'show_calculator', 'yes' );
        $this->show_on_product        = 'yes' === $this->get_option( 'show_on_product', 'no' );
        $this->show_btn_on_product    = 'yes' === $this->get_option( 'show_btn_on_product', 'no' );
        $this->show_on_catalog        = 'yes' === $this->get_option( 'show_on_catalog', 'no' );
        $this->show_on_cart           = 'yes' === $this->get_option( 'show_on_cart', 'no' );
        $this->show_on_checkout       = 'yes' === $this->get_option( 'show_on_checkout', 'no' );
        $this->informer_text          = $this->get_option( 'informer_text', 'от {amount} ₽/мес в рассрочку' );
        $this->excluded_categories    = $this->get_option( 'excluded_categories', array() );
        $this->notify_admin_email     = 'yes' === $this->get_option( 'notify_admin_email', 'no' );
        $this->notify_customer_email  = 'yes' === $this->get_option( 'notify_customer_email', 'no' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_api_tbank_credit', array( $this, 'handle_webhook' ) );

        if ( $this->show_on_product ) {
            add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'product_informer' ) );
        }
        if ( $this->show_btn_on_product ) {
            add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'product_credit_button' ) );
        }
        if ( $this->show_on_cart ) {
            add_action( 'woocommerce_cart_actions', array( $this, 'cart_credit_button' ) );
        }
        if ( $this->show_on_checkout ) {
            add_action( 'woocommerce_review_order_before_submit', array( $this, 'checkout_credit_button' ) );
        }

        if ( is_admin() ) {
            add_action( 'admin_footer', array( $this, 'admin_footer_script' ) );
        }
    }

    // ─── Inline SVG helper ───────────────────────────────────────────────────

    public function get_svg_icon( $size = 22 ) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="' . intval( $size ) . '" height="' . intval( $size ) . '" aria-hidden="true" focusable="false" class="tbank-credit-icon"><rect width="32" height="32" rx="6" fill="#1a1a1a"/><text x="16" y="23" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="900" font-size="20" fill="#FFDD2D">Т</text></svg>';
    }

    // ─── Admin description ────────────────────────────────────────────────────

    public function get_method_description() {
        ob_start();
        ?>
        <div class="tbank-credit-admin-desc">
            <p><strong>Т-Банк Рассрочка и Кредит v<?php echo TBANK_CREDIT_VERSION; ?></strong> — профессиональный платёжный шлюз для WooCommerce.<br>
            Разработчик: <a href="https://рукодер.рф/" target="_blank">Сергей Солошенко (RuCoder)</a> &nbsp;|&nbsp; <a href="https://t.me/RussCoder" target="_blank">@RussCoder</a></p>
            <hr>
            <h3>📋 Как подключить</h3>
            <ol>
                <li>Зарегистрируйтесь как партнёр: <a href="https://www.tbank.ru/business/credits/credit-partner/" target="_blank">tbank.ru/business/credits/credit-partner/</a></li>
                <li>Получите <strong>Shop ID</strong> и <strong>Showcase ID</strong> в <a href="https://business.tbank.ru/posbroker" target="_blank">Личном кабинете Т-Банка</a>.</li>
                <li>Укажите <strong>Пароль API</strong> из раздела «Магазины» → «Настройки API».</li>
                <li>Нажмите «Проверить реквизиты» — плагин сделает реальный запрос к API Т-Банка.</li>
                <li>Включите <strong>тестовый режим</strong> и оформите пробный заказ.</li>
                <li>После тестирования переключитесь в <strong>рабочий режим</strong>.</li>
            </ol>
            <h3>🔔 Настройка уведомлений (Webhook)</h3>
            <ol>
                <li>Войдите в <a href="https://business.tbank.ru/posbroker" target="_blank">Личный кабинет Т-Банка</a>.</li>
                <li>Перейдите: «Кредитование в магазинах» → выберите магазин → «Редактировать» → «Уведомления».</li>
                <li>Укажите <strong>Адрес для HTTP-нотификаций</strong>:</li>
            </ol>
            <code style="background:#f5f5f5;padding:5px 10px;display:block;margin:5px 0;"><?php echo esc_url( home_url( '/wc-api/tbank_credit/' ) ); ?></code>
            <h3>📄 Документация API</h3>
            <p>
                <a href="https://forma.tbank.ru/docs/online/setup-types/api/testing-api" target="_blank">→ API документация</a> &nbsp;|&nbsp;
                <a href="https://forma.tbank.ru/docs/online/" target="_blank">→ Общая документация</a> &nbsp;|&nbsp;
                <a href="https://www.tbank.ru/business/credits/credit-partner/" target="_blank">→ Стать партнёром</a> &nbsp;|&nbsp;
                <a href="https://рукодер.рф/" target="_blank">→ Поддержка RuCoder</a>
            </p>
            <hr>
            <p style="color:#856404;background:#fff3cd;padding:10px;border-radius:4px;margin:0;">
                ⚠️ <strong>Важно:</strong> минимальная сумма заказа — <strong>3 000 ₽</strong>. Страна покупателя — <strong>Россия</strong>.
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    // ─── Form fields ──────────────────────────────────────────────────────────

    public function init_form_fields() {
        $categories  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
        $cat_options = array();
        if ( ! is_wp_error( $categories ) ) {
            foreach ( $categories as $cat ) {
                $cat_options[ $cat->term_id ] = $cat->name;
            }
        }

        $this->form_fields = array(

            // ── ОСНОВНЫЕ ─────────────────────────────────────────────────────
            'section_main' => array(
                'title' => __( '⚙️ Основные настройки', 'tbank-credit-woocommerce' ),
                'type'  => 'title',
            ),
            'enabled' => array(
                'title'   => __( 'Включить / Выключить', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Включить «Т-Банк Рассрочка и Кредит»', 'tbank-credit-woocommerce' ),
                'default' => 'no',
            ),
            'test_mode' => array(
                'title'       => __( 'Режим работы', 'tbank-credit-woocommerce' ),
                'type'        => 'select',
                'description' => __( 'Тестовый режим — для проверки интеграции (заявки не реальные). Рабочий — реальные транзакции.', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'options'     => array(
                    'yes' => __( '🧪 Тестовый режим (sandbox)', 'tbank-credit-woocommerce' ),
                    'no'  => __( '✅ Рабочий режим (production)', 'tbank-credit-woocommerce' ),
                ),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __( 'Название метода оплаты', 'tbank-credit-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Название, которое покупатель видит при оформлении заказа.', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'default'     => 'Рассрочка или кредит от Т-Банка',
            ),
            'description' => array(
                'title'       => __( 'Описание метода оплаты', 'tbank-credit-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Описание под названием метода оплаты на чекауте.', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'default'     => 'Оформите покупку в рассрочку или кредит через Т-Банк. Решение за несколько минут.',
            ),
            'order_button_text' => array(
                'title'       => __( 'Текст кнопки оформления заказа', 'tbank-credit-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Текст кнопки «Оформить заказ» на странице чекаута при выборе метода «Т-Банк».', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'default'     => 'Оформить заявку в Т-Банк',
            ),

            // ── ДАННЫЕ МАГАЗИНА ───────────────────────────────────────────────
            'section_credentials' => array(
                'title'       => __( '🔑 Данные магазина (от Т-Банка)', 'tbank-credit-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Идентификаторы и пароль предоставляет менеджер Т-Банка или доступны в личном кабинете.', 'tbank-credit-woocommerce' ),
            ),
            'shop_id' => array(
                'title'       => __( 'Shop ID (ID магазина)', 'tbank-credit-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Уникальный числовой идентификатор вашего магазина. Пример: 25851625', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'placeholder' => '25851625',
            ),
            'showcase_id' => array(
                'title'       => __( 'Showcase ID (ID витрины)', 'tbank-credit-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Идентификатор витрины из Личного кабинета. Пример: MB0002265612', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'placeholder' => 'MB0002265612',
            ),
            'api_password' => array(
                'title'       => __( 'Пароль API', 'tbank-credit-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Пароль используется для получения статусов заявки и проверки webhook-подписи. Задайте в ЛК: «Магазины» → «Настройки API». Если пусто — проверка подписи отключена (не рекомендуется в рабочем режиме).', 'tbank-credit-woocommerce' ),
                'placeholder' => '••••••••••••',
                'default'     => '',
            ),
            'test_connection_button' => array(
                'title'       => __( 'Проверка реквизитов', 'tbank-credit-woocommerce' ),
                'type'        => 'title',
                'description' =>
                    '<button type="button" id="tbank-credit-test-btn" class="button button-secondary">' .
                    __( '🔌 Проверить реквизиты через API Т-Банка', 'tbank-credit-woocommerce' ) .
                    '</button>' .
                    '<p style="margin-top:6px;color:#888;font-size:12px;">ℹ️ Кнопка делает тестовый запрос к реальному API Т-Банка и сообщает, существует ли комбинация Shop ID + Showcase ID в системе банка.</p>' .
                    '<div id="tbank-credit-test-result" style="margin-top:8px;font-size:13px;line-height:1.5;"></div>',
            ),

            // ── ПРОМО-КОДЫ ────────────────────────────────────────────────────
            'section_promo' => array(
                'title'       => __( '🎁 Промо-коды и рассрочка', 'tbank-credit-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Промо-коды определяют тип кредитного продукта. Для рассрочки без переплат используйте <code>installment_0_0_</code>. Уточните актуальные коды у менеджера Т-Банка.', 'tbank-credit-woocommerce' ),
            ),
            'promo_codes' => array(
                'title'       => __( 'Промо-коды', 'tbank-credit-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Каждый промо-код с новой строки или через запятую. Первый будет использован по умолчанию. Для рассрочки: <code>installment_0_0_</code>', 'tbank-credit-woocommerce' ),
                'placeholder' => "installment_0_0_\ncredit_0_0_",
                'default'     => '',
            ),
            'installment_periods' => array(
                'title'       => __( 'Периоды рассрочки (месяцев)', 'tbank-credit-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Через запятую без пробелов. Пример: 3,6,12,24. Пусто — калькулятор использует 6 мес.', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'placeholder' => '3,6,12,24',
                'default'     => '',
            ),
            'enable_cooling' => array(
                'title'   => __( 'Период охлаждения', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Учитывать период охлаждения (commit_cooldown)', 'tbank-credit-woocommerce' ),
                'description' => __( 'Период охлаждения — пауза после подписания договора перед финальной выдачей кредита/рассрочки. При включении заказ не переводится в «В обработке» до истечения периода охлаждения.', 'tbank-credit-woocommerce' ),
                'default' => 'no',
            ),

            // ── КНОПКА ────────────────────────────────────────────────────────
            'section_button' => array(
                'title'       => __( '🔘 Настройки кнопки', 'tbank-credit-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Кнопка «Купить в рассрочку / кредит» выводится на страницах по вашему выбору.', 'tbank-credit-woocommerce' ),
            ),
            'btn_text_credit' => array(
                'title'       => __( 'Текст кнопки «Кредит»', 'tbank-credit-woocommerce' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => __( 'Текст на кнопке кредита.', 'tbank-credit-woocommerce' ),
                'default'     => 'Купить в кредит',
            ),
            'btn_text_install' => array(
                'title'       => __( 'Текст кнопки «Рассрочка»', 'tbank-credit-woocommerce' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => __( 'Текст на кнопке рассрочки.', 'tbank-credit-woocommerce' ),
                'default'     => 'Купить в рассрочку',
            ),
            'btn_color' => array(
                'title'       => __( 'Цвет кнопки', 'tbank-credit-woocommerce' ),
                'type'        => 'color',
                'description' => __( 'Цвет фона кнопки. По умолчанию — фирменный цвет Т-Банка (#FFDD2D).', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'default'     => '#FFDD2D',
            ),
            'btn_radius' => array(
                'title'       => __( 'Скругление кнопки (px)', 'tbank-credit-woocommerce' ),
                'type'        => 'number',
                'description' => __( 'Радиус скругления углов в пикселях.', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'default'     => '8',
                'custom_attributes' => array( 'min' => '0', 'max' => '50', 'step' => '1' ),
            ),
            'open_new_window' => array(
                'title'   => __( 'Открывать в новой вкладке', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Открывать страницу оформления кредита в новой вкладке браузера', 'tbank-credit-woocommerce' ),
                'default' => 'no',
            ),
            'show_calculator' => array(
                'title'   => __( 'AJAX-калькулятор рассрочки', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Показывать интерактивный калькулятор ежемесячного платежа на странице товара', 'tbank-credit-woocommerce' ),
                'default' => 'yes',
            ),

            // ── РАСПОЛОЖЕНИЕ КНОПКИ ───────────────────────────────────────────
            'section_locations' => array(
                'title'       => __( '📍 Где показывать кнопку', 'tbank-credit-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Выберите страницы, на которых будет отображаться кнопка «Купить в рассрочку / кредит».', 'tbank-credit-woocommerce' ),
            ),
            'show_on_product' => array(
                'title'   => __( 'Страница товара — информер', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Показывать информер «от X ₽/мес» над кнопкой «Добавить в корзину»', 'tbank-credit-woocommerce' ),
                'default' => 'no',
            ),
            'informer_text' => array(
                'title'       => __( 'Текст информера', 'tbank-credit-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Используйте {amount} — подставится расчётный ежемесячный платёж.', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'default'     => 'от {amount} ₽/мес в рассрочку',
            ),
            'show_btn_on_product' => array(
                'title'   => __( 'Страница товара — кнопка', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Показывать кнопку «Купить в кредит/рассрочку» под кнопкой «Добавить в корзину»', 'tbank-credit-woocommerce' ),
                'default' => 'no',
            ),
            'show_on_catalog' => array(
                'title'   => __( 'Каталог товаров', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Показывать кнопку в карточках товаров в каталоге', 'tbank-credit-woocommerce' ),
                'default' => 'no',
            ),
            'show_on_cart' => array(
                'title'   => __( 'Корзина', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Показывать кнопку на странице корзины', 'tbank-credit-woocommerce' ),
                'default' => 'no',
            ),
            'show_on_checkout' => array(
                'title'   => __( 'Оформление заказа (Checkout)', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Показывать кнопку на странице оформления заказа над кнопкой «Оформить заказ»', 'tbank-credit-woocommerce' ),
                'default' => 'no',
            ),

            // ── ИСКЛЮЧЕНИЯ ────────────────────────────────────────────────────
            'section_exclusions' => array(
                'title'       => __( '🚫 Исключения', 'tbank-credit-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Категории, для которых кнопка рассрочки/кредита не будет показываться. Конкретные товары можно отключить в редакторе товара.', 'tbank-credit-woocommerce' ),
            ),
            'excluded_categories' => array(
                'title'             => __( 'Исключить категории', 'tbank-credit-woocommerce' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'description'       => __( 'Выберите категории, в которых кнопка не отображается.', 'tbank-credit-woocommerce' ),
                'desc_tip'          => true,
                'options'           => $cat_options,
                'default'           => array(),
                'custom_attributes' => array( 'data-placeholder' => __( 'Выберите категории...', 'tbank-credit-woocommerce' ) ),
            ),

            // ── СУММЫ ─────────────────────────────────────────────────────────
            'section_amounts' => array(
                'title' => __( '💰 Суммы заказа', 'tbank-credit-woocommerce' ),
                'type'  => 'title',
            ),
            'min_amount' => array(
                'title'       => __( 'Минимальная сумма (₽)', 'tbank-credit-woocommerce' ),
                'type'        => 'number',
                'description' => __( 'Кнопка и метод оплаты не показываются при сумме ниже указанной. Минимум — 3 000 ₽.', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'default'     => '3000',
                'custom_attributes' => array( 'min' => '3000', 'step' => '100' ),
            ),
            'max_amount' => array(
                'title'       => __( 'Максимальная сумма (₽)', 'tbank-credit-woocommerce' ),
                'type'        => 'number',
                'description' => __( 'Кнопка не показывается при сумме выше указанной. 0 — без ограничений.', 'tbank-credit-woocommerce' ),
                'desc_tip'    => true,
                'default'     => '0',
                'custom_attributes' => array( 'min' => '0', 'step' => '1000' ),
            ),

            // ── СТАТУСЫ ЗАКАЗОВ ───────────────────────────────────────────────
            'section_statuses' => array(
                'title' => __( '📦 Статусы заказов', 'tbank-credit-woocommerce' ),
                'type'  => 'title',
            ),
            'success_status' => array(
                'title'       => __( 'Статус при одобрении кредита', 'tbank-credit-woocommerce' ),
                'type'        => 'select',
                'desc_tip'    => true,
                'description' => __( 'Устанавливается после одобрения (signed) кредита Т-Банком.', 'tbank-credit-woocommerce' ),
                'options'     => array(
                    'processing' => 'В обработке',
                    'completed'  => 'Выполнен',
                    'on-hold'    => 'Ожидание',
                ),
                'default' => 'processing',
            ),
            'cancel_status' => array(
                'title'       => __( 'Статус при отмене/отказе', 'tbank-credit-woocommerce' ),
                'type'        => 'select',
                'desc_tip'    => true,
                'description' => __( 'Устанавливается при отказе (rejected) или отмене (canceled) кредитной заявки.', 'tbank-credit-woocommerce' ),
                'options'     => array(
                    'cancelled' => 'Отменён',
                    'failed'    => 'Не выполнен',
                    'on-hold'   => 'Ожидание',
                ),
                'default' => 'cancelled',
            ),

            // ── УВЕДОМЛЕНИЯ ───────────────────────────────────────────────────
            'section_notifications' => array(
                'title'       => __( '📧 Email-уведомления', 'tbank-credit-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Отправлять уведомления об изменении статуса кредитной заявки.', 'tbank-credit-woocommerce' ),
            ),
            'notify_admin_email' => array(
                'title'   => __( 'Уведомлять администратора', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Отправлять email администратору при изменении статуса заявки', 'tbank-credit-woocommerce' ),
                'default' => 'no',
            ),
            'notify_customer_email' => array(
                'title'   => __( 'Уведомлять покупателя', 'tbank-credit-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Отправлять email покупателю при одобрении или отказе кредита', 'tbank-credit-woocommerce' ),
                'default' => 'no',
            ),

            // ── ОТЛАДКА ───────────────────────────────────────────────────────
            'section_debug' => array(
                'title' => __( '🛠️ Отладка', 'tbank-credit-woocommerce' ),
                'type'  => 'title',
            ),
            'debug' => array(
                'title'       => __( 'Логирование', 'tbank-credit-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Записывать отладочную информацию в лог WooCommerce', 'tbank-credit-woocommerce' ),
                'description' => sprintf(
                    __( 'Логи: <a href="%s">WooCommerce → Статус → Логи</a>.', 'tbank-credit-woocommerce' ),
                    admin_url( 'admin.php?page=wc-status&tab=logs' )
                ),
                'default' => 'no',
            ),
        );
    }

    // ─── Availability ─────────────────────────────────────────────────────────

    public function is_available() {
        if ( ! parent::is_available() ) {
            return false;
        }
        if ( empty( $this->shop_id ) || empty( $this->showcase_id ) ) {
            return false;
        }
        if ( WC()->cart ) {
            $cart_total = WC()->cart->get_cart_contents_total();
            if ( $cart_total < $this->min_amount ) {
                return false;
            }
            if ( $this->max_amount > 0 && $cart_total > $this->max_amount ) {
                return false;
            }
        }
        $customer = WC()->customer;
        if ( $customer ) {
            $country = $customer->get_billing_country();
            if ( ! empty( $country ) && $country !== 'RU' ) {
                return false;
            }
        }
        return true;
    }

    // ─── Payment fields (promo selector on checkout) ──────────────────────────

    public function payment_fields() {
        parent::payment_fields();

        $promos = $this->get_promo_codes_array();

        if ( count( $promos ) > 1 ) {
            echo '<div class="tbank-credit-promo-select" style="margin-top:10px;">';
            echo '<label><strong>' . esc_html__( 'Тип финансирования:', 'tbank-credit-woocommerce' ) . '</strong></label><br>';
            foreach ( $promos as $i => $code ) {
                $label = strpos( $code, 'installment' ) !== false ? 'Рассрочка' : 'Кредит';
                printf(
                    '<label style="display:inline-flex;align-items:center;gap:6px;margin-right:16px;cursor:pointer;"><input type="radio" name="tbank_promo_code" value="%s" %s> %s</label>',
                    esc_attr( $code ),
                    $i === 0 ? 'checked' : '',
                    esc_html( $label . ' (' . $code . ')' )
                );
            }
            echo '</div>';
        } elseif ( count( $promos ) === 1 ) {
            echo '<input type="hidden" name="tbank_promo_code" value="' . esc_attr( $promos[0] ) . '">';
        }

        if ( $this->test_mode && current_user_can( 'manage_woocommerce' ) ) {
            echo '<div style="background:#fff3cd;border:1px solid #ffc107;padding:8px 12px;border-radius:4px;margin-top:10px;font-size:12px;">';
            echo '<strong>🧪 Тестовый режим:</strong> будет создана демо-заявка.';
            echo '</div>';
        }
    }

    // ─── Process payment via T-Bank REST API ──────────────────────────────────

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Ошибка: заказ не найден.', 'tbank-credit-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        if ( empty( $this->shop_id ) || empty( $this->showcase_id ) ) {
            wc_add_notice( __( 'Ошибка: не указаны Shop ID или Showcase ID. Обратитесь к администратору.', 'tbank-credit-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $this->log( 'process_payment start for order #' . $order_id );

        // Определяем промо-код (выбранный пользователем или первый по умолчанию)
        $promo_code = isset( $_POST['tbank_promo_code'] ) ? sanitize_text_field( $_POST['tbank_promo_code'] ) : '';
        if ( empty( $promo_code ) ) {
            $promos     = $this->get_promo_codes_array();
            $promo_code = ! empty( $promos ) ? $promos[0] : '';
        }

        // Строим тело запроса к API
        $body = $this->build_api_request( $order, $promo_code );

        // Выбираем endpoint
        $endpoint = $this->test_mode ? '/create-demo' : '/create';
        $api_url  = self::API_BASE . $endpoint;

        $this->log( 'API request to: ' . $api_url );

        $response = wp_remote_post( $api_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type'    => 'application/json',
                'Accept'          => 'application/json',
                'integrationType' => 'plugin',
            ),
            'body'       => wp_json_encode( $body ),
            'user-agent' => 'TBank-WooCommerce-Plugin/' . TBANK_CREDIT_VERSION,
        ) );

        if ( is_wp_error( $response ) ) {
            $error = $response->get_error_message();
            $this->log( 'API request error: ' . $error, 'error' );
            wc_add_notice( __( 'Ошибка соединения с Т-Банком: ', 'tbank-credit-woocommerce' ) . $error, 'error' );
            return array( 'result' => 'failure' );
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body_raw  = wp_remote_retrieve_body( $response );
        $decoded   = json_decode( $body_raw, true );

        $this->log( 'API response HTTP ' . $http_code . ': ' . $body_raw );

        // Проверяем ошибки от API
        if ( ! empty( $decoded['errors'] ) ) {
            $error_text = is_array( $decoded['errors'] ) ? implode( '; ', $decoded['errors'] ) : $decoded['errors'];
            $this->log( 'API errors: ' . $error_text, 'error' );
            wc_add_notice(
                __( 'Ошибка Т-Банка: ', 'tbank-credit-woocommerce' ) . esc_html( $error_text ) .
                __( '. Пожалуйста, проверьте реквизиты магазина в настройках плагина или выберите другой способ оплаты.', 'tbank-credit-woocommerce' ),
                'error'
            );
            return array( 'result' => 'failure' );
        }

        // Нужна ссылка для редиректа
        if ( empty( $decoded['link'] ) ) {
            $this->log( 'API: no link in response. HTTP ' . $http_code, 'error' );
            wc_add_notice(
                __( 'Т-Банк не вернул ссылку для оформления кредита. Попробуйте позже или выберите другой способ оплаты.', 'tbank-credit-woocommerce' ),
                'error'
            );
            return array( 'result' => 'failure' );
        }

        // Сохраняем ID заявки из ответа API
        if ( ! empty( $decoded['id'] ) ) {
            $order->update_meta_data( '_tbank_credit_application_id', sanitize_text_field( $decoded['id'] ) );
        }

        $order->update_status( 'pending', __( 'Ожидание ответа от Т-Банка (рассрочка/кредит).', 'tbank-credit-woocommerce' ) );
        $order->save();

        wc_reduce_stock_levels( $order_id );
        WC()->cart->empty_cart();

        $redirect_url = $decoded['link'];
        $this->log( 'Redirecting to T-Bank: ' . $redirect_url );

        return array(
            'result'   => 'success',
            'redirect' => $redirect_url,
        );
    }

    /**
     * Build the API request body for creating a T-Bank credit order.
     *
     * @param WC_Order $order
     * @param string   $promo_code
     * @return array
     */
    private function build_api_request( WC_Order $order, string $promo_code = '' ): array {
        $items = array();

        foreach ( $order->get_items() as $item ) {
            $item_data = array(
                'name'     => $item->get_name(),
                'price'    => (float) wc_format_decimal( $item->get_total() / max( 1, $item->get_quantity() ), 2 ),
                'quantity' => (int) $item->get_quantity(),
            );

            $product = $item->get_product();
            if ( $product && $product->get_sku() ) {
                $item_data['vendorCode'] = $product->get_sku();
            }

            $items[] = $item_data;
        }

        // Доставка
        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            $shipping_total = (float) $shipping_item->get_total();
            if ( $shipping_total > 0 ) {
                $items[] = array(
                    'name'     => 'Доставка: ' . $shipping_item->get_name(),
                    'price'    => (float) wc_format_decimal( $shipping_total, 2 ),
                    'quantity' => 1,
                );
            }
        }

        $params = array(
            'shopId'          => $this->shop_id,
            'showcaseId'      => $this->showcase_id,
            'sum'             => (float) wc_format_decimal( $order->get_total(), 2 ),
            'integrationType' => 'plugin',
            'orderNumber'     => (string) $order->get_order_number(),
            'items'           => $items,
            'failURL'         => esc_url_raw( $order->get_checkout_payment_url() ),
            'successURL'      => esc_url_raw( $this->get_return_url( $order ) ),
            'returnURL'       => esc_url_raw( $order->get_cancel_order_url_raw() ),
            'webhookURL'      => esc_url_raw( home_url( '/wc-api/tbank_credit/' ) ),
            'values'          => array(
                'contact' => array(
                    'fio'         => array(
                        'lastName'  => $order->get_billing_last_name(),
                        'firstName' => $order->get_billing_first_name(),
                    ),
                    'mobilePhone' => preg_replace( '/[^0-9]/', '', $order->get_billing_phone() ),
                    'email'       => $order->get_billing_email(),
                ),
            ),
        );

        if ( ! empty( $promo_code ) ) {
            $params['promoCode'] = $promo_code;
        }

        // Тестовый режим: дополнительные параметры для demo
        if ( $this->test_mode ) {
            $params['demoFlow']     = 'sms';
            $params['initialStage'] = 'filling';
        }

        return apply_filters( 'tbank_credit_api_request_params', $params, $order, $this );
    }

    /**
     * Get application info from T-Bank API (for status checks).
     *
     * @param string $order_number
     * @return array|WP_Error
     */
    public function get_application_info( string $order_number ) {
        $showcase_id = $this->showcase_id;
        if ( $this->test_mode ) {
            $showcase_id = 'demo-' . $showcase_id;
        }

        $url = self::API_BASE . '/' . rawurlencode( $order_number ) . '/info';

        $response = wp_remote_get( $url, array(
            'timeout'    => 15,
            'headers'    => array(
                'Accept'        => 'application/json',
                'Authorization' => 'Basic ' . base64_encode( $showcase_id . ':' . $this->api_password ),
            ),
            'user-agent' => 'TBank-WooCommerce-Plugin/' . TBANK_CREDIT_VERSION,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        return $decoded ?? array();
    }

    /**
     * Build a direct T-Bank URL (for standalone buttons on product/cart pages).
     * Falls back to the old forma.tbank.ru URL with GET params for direct links
     * (without creating a WC order).
     */
    public function build_direct_url( $amount = 0 ) {
        $params = array(
            'shopId'     => $this->shop_id,
            'showcaseId' => $this->showcase_id,
        );
        if ( $amount > 0 ) {
            $params['sum'] = (int) round( $amount );
        }
        $promo_codes = $this->get_promo_codes_array();
        if ( ! empty( $promo_codes ) ) {
            $params['promoCode'] = $promo_codes[0];
        }
        if ( $this->test_mode ) {
            $params['demo'] = 'true';
        }
        return add_query_arg( array_map( 'rawurlencode', $params ), 'https://forma.tbank.ru/' );
    }

    /**
     * Parse promo codes from settings into an array.
     */
    public function get_promo_codes_array() {
        $raw = $this->get_option( 'promo_codes', '' );
        if ( empty( $raw ) ) {
            return array();
        }
        return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw ) ) ) );
    }

    // ─── Product/category exclusion check ─────────────────────────────────────

    public function is_product_excluded( $product ) {
        if ( ! $product ) {
            return false;
        }
        if ( '1' === get_post_meta( $product->get_id(), '_tbank_credit_disabled', true ) ) {
            return true;
        }
        $excluded = $this->get_option( 'excluded_categories', array() );
        if ( ! empty( $excluded ) && is_array( $excluded ) ) {
            $product_cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $product_cats ) ) {
                $overlap = array_intersect( array_map( 'intval', $excluded ), $product_cats );
                if ( ! empty( $overlap ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private function get_product_display_price( $product ) {
        if ( $product->is_type( 'variable' ) ) {
            return (float) $product->get_variation_price( 'min' );
        }
        return (float) $product->get_price();
    }

    // ─── Display hooks ────────────────────────────────────────────────────────

    public function render_credit_button( $amount = 0 ) {
        $promo_codes = $this->get_promo_codes_array();
        $is_install  = ! empty( $promo_codes ) && strpos( $promo_codes[0], 'installment' ) !== false;
        $btn_text    = $is_install ? $this->btn_text_install : $this->btn_text_credit;
        $url         = $this->build_direct_url( $amount );
        $target      = $this->open_new_window ? ' target="_blank" rel="noopener noreferrer"' : '';
        $color       = esc_attr( $this->btn_color );
        $radius      = intval( $this->btn_radius );
        $data_amount = $amount > 0 ? ' data-amount="' . esc_attr( $amount ) . '"' : '';

        echo '<a href="' . esc_url( $url ) . '" class="tbank-credit-btn"' . $target . $data_amount
            . ' style="--tbc-color:' . $color . ';--tbc-radius:' . $radius . 'px;" aria-label="' . esc_attr( $btn_text . ' — Т-Банк' ) . '">';
        echo $this->get_svg_icon( 22 );
        echo '<span class="tbank-credit-btn__text">' . esc_html( $btn_text ) . '</span>';
        echo '</a>';
    }

    public function product_informer() {
        global $product;
        if ( ! $product || 'yes' !== $this->enabled ) {
            return;
        }
        if ( $this->is_product_excluded( $product ) ) {
            return;
        }
        $price = $this->get_product_display_price( $product );
        if ( $price < $this->min_amount ) {
            return;
        }
        if ( $this->max_amount > 0 && $price > $this->max_amount ) {
            return;
        }

        $months  = 6;
        $periods = $this->get_option( 'installment_periods', '' );
        if ( ! empty( $periods ) ) {
            $arr    = array_filter( array_map( 'trim', explode( ',', $periods ) ) );
            $months = intval( reset( $arr ) );
        }
        $monthly = ceil( $price / max( 1, $months ) );
        $text    = str_replace( '{amount}', number_format( $monthly, 0, '.', ' ' ), $this->informer_text );

        echo '<div class="tbank-credit-informer" data-price="' . esc_attr( $price ) . '" data-months="' . esc_attr( $months ) . '">';
        echo $this->get_svg_icon( 20 );
        echo '<span class="tbank-credit-informer__text">' . esc_html( $text ) . '</span>';
        echo '</div>';

        if ( $this->show_calculator && ! empty( $periods ) ) {
            $this->render_calculator( $price, $periods );
        }
    }

    private function render_calculator( $price, $periods_str ) {
        $periods = array_filter( array_map( 'trim', explode( ',', $periods_str ) ) );
        if ( count( $periods ) < 2 ) {
            return;
        }
        echo '<div class="tbank-credit-calculator" data-price="' . esc_attr( $price ) . '">';
        echo '<div class="tbank-credit-calculator__label">Выберите срок рассрочки:</div>';
        echo '<div class="tbank-credit-calculator__periods">';
        foreach ( $periods as $p ) {
            $months  = intval( $p );
            $monthly = ceil( $price / max( 1, $months ) );
            echo '<button type="button" class="tbank-credit-calculator__period-btn" data-months="' . esc_attr( $months ) . '" data-monthly="' . esc_attr( $monthly ) . '">';
            echo '<span class="tbank-credit-calculator__months">' . intval( $months ) . ' мес</span>';
            echo '<span class="tbank-credit-calculator__price">' . number_format( $monthly, 0, '.', ' ' ) . ' ₽/мес</span>';
            echo '</button>';
        }
        echo '</div>';
        echo '</div>';
    }

    public function product_credit_button() {
        return;
    }

    public function cart_credit_button() {
        if ( 'yes' !== $this->enabled ) {
            return;
        }
        $total = (float) WC()->cart->get_cart_contents_total();
        if ( $total < $this->min_amount ) {
            return;
        }
        if ( $this->max_amount > 0 && $total > $this->max_amount ) {
            return;
        }
        echo '<div class="tbank-credit-cart-btn-wrap">';
        $this->render_credit_button( $total );
        echo '</div>';
    }

    public function checkout_credit_button() {
        return;
    }

    // ─── Email notifications ──────────────────────────────────────────────────

    public function send_notifications( WC_Order $order, $status, $type = 'approved' ) {
        $site_name  = get_bloginfo( 'name' );
        $order_id   = $order->get_id();
        $order_link = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

        if ( $type === 'approved' ) {
            $subject_admin    = sprintf( '[%s] Кредит/рассрочка одобрена — заказ #%d', $site_name, $order_id );
            $message_admin    = sprintf( "Здравствуйте!\n\nТ-Банк одобрил заявку на рассрочку/кредит для заказа #%d.\nСтатус Т-Банка: %s\n\nСсылка на заказ:\n%s", $order_id, $status, $order_link );
            $subject_customer = sprintf( '[%s] Ваш кредит одобрен!', $site_name );
            $message_customer = sprintf( "Здравствуйте, %s!\n\nТ-Банк одобрил вашу заявку на рассрочку/кредит для заказа #%d.\nСкоро мы начнём обработку вашего заказа.\n\nС уважением,\n%s", $order->get_billing_first_name(), $order_id, $site_name );
        } else {
            $subject_admin    = sprintf( '[%s] Кредит/рассрочка отклонена — заказ #%d', $site_name, $order_id );
            $message_admin    = sprintf( "Здравствуйте!\n\nТ-Банк отклонил заявку на рассрочку/кредит для заказа #%d.\nСтатус Т-Банка: %s\n\nСсылка на заказ:\n%s", $order_id, $status, $order_link );
            $subject_customer = sprintf( '[%s] По заявке на кредит принято решение', $site_name );
            $message_customer = sprintf( "Здравствуйте, %s!\n\nК сожалению, Т-Банк не смог одобрить вашу заявку на рассрочку/кредит для заказа #%d.\nВы можете выбрать другой способ оплаты на нашем сайте.\n\nС уважением,\n%s", $order->get_billing_first_name(), $order_id, $site_name );
        }

        if ( $this->notify_admin_email ) {
            wp_mail( get_option( 'admin_email' ), $subject_admin, $message_admin );
            $this->log( 'Admin notification sent for order #' . $order_id );
        }
        if ( $this->notify_customer_email && $order->get_billing_email() ) {
            wp_mail( $order->get_billing_email(), $subject_customer, $message_customer );
            $this->log( 'Customer notification sent for order #' . $order_id );
        }
    }

    // ─── Thank you / webhook ──────────────────────────────────────────────────

    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            echo '<div class="tbank-credit-thankyou">';
            echo $this->get_svg_icon( 28 );
            echo '<p>' . esc_html__( 'Спасибо! Ваша заявка на кредит/рассрочку передана в Т-Банк. Статус заказа обновится автоматически после решения банка.', 'tbank-credit-woocommerce' ) . '</p>';
            echo '</div>';
        }
    }

    public function handle_webhook() {
        $handler = new WC_TBank_Credit_Webhook( $this );
        $handler->process();
    }

    // ─── Admin ────────────────────────────────────────────────────────────────

    public function admin_options() {
        // Если плагин не активирован — показываем заблокированные настройки
        if ( class_exists( 'TBank_Credit_License' ) && ! TBank_Credit_License::is_active() ) {
            $activate_url = admin_url( 'admin.php?page=' . TBank_Credit_License::ADMIN_PAGE );
            echo '<div class="notice notice-warning inline" style="margin:16px 0;">';
            echo '<p>🔒 <strong>Плагин не активирован.</strong> Для доступа к настройкам необходима активация. ';
            echo '<a href="' . esc_url( $activate_url ) . '" class="button button-primary">Активировать плагин</a></p>';
            echo '</div>';
            $this->render_locked_settings();
            return;
        }

        if ( 'yes' === $this->enabled ) {
            if ( empty( $this->shop_id ) || empty( $this->showcase_id ) ) {
                echo '<div class="notice notice-error inline"><p>';
                echo '⛔ <strong>Т-Банк Рассрочка:</strong> ' . esc_html__( 'Для работы шлюза необходимо заполнить Shop ID и Showcase ID.', 'tbank-credit-woocommerce' );
                echo '</p></div>';
            }
        }
        parent::admin_options();
    }

    /**
     * Render locked placeholder settings when plugin is not activated.
     */
    private function render_locked_settings(): void {
        $activate_url = admin_url( 'admin.php?page=' . TBank_Credit_License::ADMIN_PAGE );

        $locked_html = '<span style="display:inline-flex;align-items:center;gap:8px;background:#fff3cd;border:1px solid #ffc107;'
            . 'padding:7px 14px;border-radius:5px;cursor:pointer;font-size:13px;" '
            . 'onclick="window.location=\'' . esc_url( $activate_url ) . '\'">'
            . '🔒 Заблокировано — <a href="' . esc_url( $activate_url ) . '">активируйте плагин</a>'
            . '</span>'
            . '<p class="description" style="margin-top:5px;">Для ввода данных необходимо активировать плагин.</p>';

        $fields = array(
            'Shop ID (ID магазина)',
            'Showcase ID (ID витрины)',
            'Пароль API',
            'Режим работы (Тест / Боевой)',
            'Название метода оплаты',
            'Промо-коды и рассрочка',
            'Настройки кнопки',
            'Где показывать кнопку',
            'Суммы заказа',
            'Статусы заказов',
            'Email-уведомления',
            'Логирование',
        );

        echo '<h2>' . esc_html__( 'Т-Банк Рассрочка и Кредит', 'tbank-credit-woocommerce' ) . '</h2>';
        echo '<table class="form-table">';
        foreach ( $fields as $label ) {
            echo '<tr valign="top">';
            echo '<th scope="row"><label>' . esc_html( $label ) . '</label></th>';
            echo '<td>' . $locked_html . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    public function admin_footer_script() {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'woocommerce_page_wc-settings' ) === false ) {
            return;
        }
        ?>
        <script>
        (function($){
            var $btn    = $('#tbank-credit-test-btn');
            var $result = $('#tbank-credit-test-result');
            if ( ! $btn.length ) return;
            $btn.on('click', function(){
                var shopId     = $('[id$=shop_id]').last().val();
                var showcaseId = $('[id$=showcase_id]').last().val();
                $btn.prop('disabled', true).text('⏳ Проверяем...');
                $result.html('').hide();
                $.ajax({
                    url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                    type: 'POST',
                    data: {
                        action: 'tbank_credit_test_connection',
                        nonce: '<?php echo wp_create_nonce( 'tbank_credit_admin_nonce' ); ?>',
                        shop_id: shopId,
                        showcase_id: showcaseId
                    },
                    success: function(res){
                        var style = res.success
                            ? 'background:#f0fff4;border:1px solid #6fcf97;'
                            : 'background:#fff5f5;border:1px solid #f5c6cb;';
                        $result.html(res.data.message).attr('style', style + 'border-radius:6px;padding:10px 14px;display:block;').show();
                    },
                    error: function(){
                        $result.html('❌ Ошибка запроса. Попробуйте ещё раз.').attr('style','background:#fff5f5;border:1px solid #f5c6cb;border-radius:6px;padding:10px 14px;display:block;').show();
                    }
                }).always(function(){
                    $btn.prop('disabled', false).text('🔌 Проверить реквизиты через API Т-Банка');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ─── Logging ─────────────────────────────────────────────────────────────

    public function log( $message, $level = 'info' ) {
        if ( 'yes' !== $this->get_option( 'debug', 'no' ) ) {
            return;
        }
        if ( class_exists( 'WC_Logger' ) ) {
            $logger  = wc_get_logger();
            $context = array( 'source' => 'tbank-credit' );
            switch ( $level ) {
                case 'error':
                    $logger->error( $message, $context );
                    break;
                case 'warning':
                    $logger->warning( $message, $context );
                    break;
                default:
                    $logger->info( $message, $context );
                    break;
            }
        }
    }
}
