<?php
defined( 'ABSPATH' ) || exit;

/**
 * WC_TBank_Credit_Webhook — обработка HTTP-уведомлений от Т-Банка.
 *
 * Т-Банк отправляет POST-запросы на URL: https://yoursite.com/wc-api/tbank_credit/
 *
 * Формат данных (JSON из php://input):
 *   {
 *     "id":            "12345",         // Номер заказа (orderNumber)
 *     "status":        "signed",        // Статус заявки
 *     "committed":     true,            // Подтверждена ли
 *     "order_amount":  50000,           // Сумма заказа (рублей)
 *     "loan_number":   "КД-123456",     // Номер кредитного договора
 *     "product":       "installment",   // Тип продукта: credit | installment | installment_credit
 *     "commit_cooldown": {              // Период охлаждения (если включён)
 *       "has_happened": false,
 *       "until": "2026-04-18T12:00:00Z"
 *     }
 *   }
 *
 * Статусы:
 *   signed   — заявка подписана (оплата одобрена)
 *   approved — предварительно одобрена (ожидает подпись)
 *   canceled — отменена покупателем
 *   rejected — отклонена банком
 *
 * Проверка подписи (для старых форматов с полем token):
 *   1. Все параметры кроме token + password → сортировка по ключу → значения через '' → SHA-256
 *
 * @author  Сергей Солошенко (RuCoder) — https://рукодер.рф/
 * @version 3.0.0
 */
class WC_TBank_Credit_Webhook {

    /** @var WC_TBank_Credit_Gateway */
    private $gateway;

    public function __construct( WC_TBank_Credit_Gateway $gateway ) {
        $this->gateway = $gateway;
    }

    /**
     * Process incoming webhook from T-Bank.
     */
    public function process() {
        $this->gateway->log( 'Webhook received. Method: ' . $_SERVER['REQUEST_METHOD'] );

        // ── Parse request body ────────────────────────────────────────────────
        $raw  = '';
        $data = null;

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            $raw = file_get_contents( 'php://input' );
            $this->gateway->log( 'Webhook raw: ' . $raw );

            // Попробуем JSON (новый API v2)
            $data = json_decode( $raw, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                // Фоллбэк на form-encoded (старый формат)
                parse_str( $raw, $data );
            }
            if ( empty( $data ) ) {
                $data = $_POST;
            }
        } else {
            $data = $_GET;
        }

        if ( empty( $data ) ) {
            $this->gateway->log( 'Webhook: empty data received.', 'error' );
            wp_die( 'Empty data', 'T-Bank Credit Webhook', array( 'response' => 400 ) );
        }

        $this->gateway->log( 'Webhook data: ' . print_r( $data, true ) );

        // ── Verify signature (если есть поле token) ───────────────────────────
        $api_password = $this->gateway->get_option( 'api_password', '' );

        if ( ! empty( $api_password ) && isset( $data['token'] ) ) {
            if ( ! $this->verify_signature( $data, $api_password ) ) {
                $this->gateway->log( 'Webhook: invalid signature — request rejected.', 'error' );
                wp_die( 'Invalid signature', 'T-Bank Credit Webhook', array( 'response' => 403 ) );
            }
            $this->gateway->log( 'Webhook: signature OK.' );
        } elseif ( empty( $api_password ) ) {
            $this->gateway->log( 'Webhook: API password not set — signature check skipped.', 'warning' );
        }

        // ── Extract fields (новый формат API v2) ──────────────────────────────
        // В новом API поле "id" = orderNumber (номер заказа WooCommerce)
        // В старом формате было "orderId"
        $order_number = '';
        $order_id     = 0;

        if ( isset( $data['id'] ) ) {
            // Новый API v2: id = orderNumber
            $order_number = sanitize_text_field( $data['id'] );
            $order        = wc_get_order( $order_number );
            if ( $order ) {
                $order_id = $order->get_id();
            }
        } elseif ( isset( $data['orderId'] ) ) {
            // Старый формат
            $order_id = absint( $data['orderId'] );
            $order    = wc_get_order( $order_id );
        } else {
            $this->gateway->log( 'Webhook: id/orderId missing.', 'error' );
            wp_die( 'Missing order identifier', 'T-Bank Credit Webhook', array( 'response' => 400 ) );
        }

        $status = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : '';

        if ( empty( $order ) || ! $order ) {
            $this->gateway->log( 'Webhook: order not found (id=' . $order_number . ', orderId=' . $order_id . ').', 'error' );
            wp_die( 'Order not found', 'T-Bank Credit Webhook', array( 'response' => 404 ) );
        }

        if ( $order->get_payment_method() !== $this->gateway->id ) {
            $this->gateway->log( 'Webhook: order uses different gateway.', 'warning' );
            wp_die( 'Wrong gateway', 'T-Bank Credit Webhook', array( 'response' => 400 ) );
        }

        // Проверка суммы (если передана в данных)
        if ( isset( $data['order_amount'] ) ) {
            $webhook_amount = (float) $data['order_amount'];
            $order_total    = (float) $order->get_total();
            if ( abs( $webhook_amount - $order_total ) > 1 ) {
                $this->gateway->log(
                    'Webhook: order amount mismatch. Webhook: ' . $webhook_amount . ', Order: ' . $order_total,
                    'warning'
                );
            }
        }

        // Сохраняем дополнительные данные о заявке
        if ( ! empty( $data['loan_number'] ) ) {
            $order->update_meta_data( '_tbank_credit_loan_number', sanitize_text_field( $data['loan_number'] ) );
        }
        if ( ! empty( $data['product'] ) ) {
            $order->update_meta_data( '_tbank_credit_product_type', sanitize_text_field( $data['product'] ) );
        }
        $order->save();

        $this->update_order_status( $order, $status, $data );

        status_header( 200 );
        echo 'OK';
        exit;
    }

    /**
     * Verify webhook signature from T-Bank.
     *
     * Algorithm (for legacy form-encoded format):
     *   1. All params excluding 'token' + add 'password' key.
     *   2. Sort alphabetically by key (case-insensitive).
     *   3. Concatenate values only.
     *   4. SHA-256 hash.
     *   5. Compare with received 'token'.
     */
    private function verify_signature( array $data, string $api_password ): bool {
        $received_token = isset( $data['token'] ) ? strtolower( trim( $data['token'] ) ) : '';

        if ( empty( $received_token ) ) {
            $this->gateway->log( 'Webhook: no token field.', 'warning' );
            return true; // нет поля — нельзя проверить, пропускаем
        }

        $params = $this->flatten_params( $data );
        unset( $params['token'] );
        $params['password'] = $api_password;

        uksort( $params, 'strcasecmp' );
        $concat   = implode( '', array_values( $params ) );
        $computed = hash( 'sha256', $concat );

        $this->gateway->log( 'Webhook token received: ' . $received_token );
        $this->gateway->log( 'Webhook token computed: ' . $computed );

        return hash_equals( $computed, $received_token );
    }

    private function flatten_params( array $data, string $prefix = '' ): array {
        $result = array();
        foreach ( $data as $key => $value ) {
            $full_key = $prefix !== '' ? $prefix . '.' . $key : $key;
            if ( is_array( $value ) ) {
                $result = array_merge( $result, $this->flatten_params( $value, $full_key ) );
            } else {
                $result[ $full_key ] = (string) $value;
            }
        }
        return $result;
    }

    /**
     * Check if credit cooldown period is still active.
     *
     * @param  array $data Webhook data
     * @return bool
     */
    private function has_active_cooldown( array $data ): bool {
        if ( ! $this->gateway->enable_cooling ) {
            return false;
        }
        if ( empty( $data['commit_cooldown'] ) ) {
            return false;
        }
        $cooldown = $data['commit_cooldown'];
        if ( is_array( $cooldown ) && isset( $cooldown['has_happened'] ) ) {
            return ! (bool) $cooldown['has_happened'];
        }
        return false;
    }

    /**
     * Update order status based on T-Bank webhook status.
     *
     * Statuses from T-Bank API v2:
     *   signed   — заявка подписана (кредит выдан)
     *   approved — предварительно одобрена, ждёт подписи клиента
     *   canceled — отменена покупателем
     *   rejected — отклонена банком
     */
    private function update_order_status( WC_Order $order, string $status, array $data ) {
        $this->gateway->log( 'Updating order #' . $order->get_id() . ' with T-Bank status: ' . $status );

        // Формируем дополнительную заметку
        $extra = '';
        if ( ! empty( $data['loan_number'] ) ) {
            $extra .= ' Договор: ' . sanitize_text_field( $data['loan_number'] ) . '.';
        }
        if ( ! empty( $data['product'] ) ) {
            $type_map = array(
                'credit'              => 'Кредит',
                'installment'         => 'Рассрочка',
                'installment_credit'  => 'Рассрочка (кредит)',
            );
            $prod = sanitize_text_field( $data['product'] );
            $extra .= ' Тип: ' . ( $type_map[ $prod ] ?? $prod ) . '.';
        }
        if ( ! empty( $data['order_amount'] ) ) {
            $extra .= ' Сумма: ' . number_format( (float) $data['order_amount'], 2, '.', ' ' ) . ' ₽.';
        }

        switch ( strtolower( $status ) ) {

            case 'signed':
                // Период охлаждения — кредит одобрен, но ещё не выдан
                if ( $this->has_active_cooldown( $data ) ) {
                    $until = '';
                    if ( ! empty( $data['commit_cooldown']['until'] ) ) {
                        $until = ' до ' . sanitize_text_field( $data['commit_cooldown']['until'] );
                    }
                    $order->update_status(
                        'on-hold',
                        __( 'Т-Банк: заявка одобрена, но находится в периоде охлаждения', 'tbank-credit-woocommerce' ) . $until . '.' . $extra
                    );
                    $this->gateway->log( 'Order #' . $order->get_id() . ' in cooldown period.' );
                } else {
                    $success_status = $this->gateway->get_option( 'success_status', 'processing' );
                    $order->update_status(
                        $success_status,
                        __( 'Т-Банк: кредит одобрен и подписан.', 'tbank-credit-woocommerce' ) . $extra
                    );
                    $order->payment_complete();
                    $this->gateway->log( 'Order #' . $order->get_id() . ' payment complete.' );
                    $this->gateway->send_notifications( $order, $status, 'approved' );
                }
                break;

            case 'approved':
                $order->update_status(
                    'on-hold',
                    __( 'Т-Банк: заявка предварительно одобрена — ожидается подпись клиента.', 'tbank-credit-woocommerce' ) . $extra
                );
                break;

            case 'rejected':
                $cancel_status = $this->gateway->get_option( 'cancel_status', 'cancelled' );
                $order->update_status(
                    $cancel_status,
                    __( 'Т-Банк: заявка отклонена банком.', 'tbank-credit-woocommerce' ) . $extra
                );
                $this->gateway->log( 'Order #' . $order->get_id() . ' rejected by bank.' );
                $this->gateway->send_notifications( $order, $status, 'rejected' );
                break;

            case 'canceled':
            case 'cancelled':
                $cancel_status = $this->gateway->get_option( 'cancel_status', 'cancelled' );
                $order->update_status(
                    $cancel_status,
                    __( 'Т-Банк: заявка отменена покупателем.', 'tbank-credit-woocommerce' ) . $extra
                );
                $this->gateway->log( 'Order #' . $order->get_id() . ' cancelled by customer.' );
                $this->gateway->send_notifications( $order, $status, 'rejected' );
                break;

            case 'preapproved':
            case 'signed_by_client':
                $order->update_status(
                    'on-hold',
                    sprintf( __( 'Т-Банк: статус «%s» — ожидается финальное подтверждение.', 'tbank-credit-woocommerce' ), $status ) . $extra
                );
                break;

            case 'initial':
            case 'new':
                $order->update_status(
                    'pending',
                    __( 'Т-Банк: заявка создана, ожидается решение банка.', 'tbank-credit-woocommerce' )
                );
                break;

            default:
                $this->gateway->log( 'Webhook: unknown status: ' . $status, 'warning' );
                $order->add_order_note(
                    sprintf( __( 'Т-Банк: получен статус «%s».', 'tbank-credit-woocommerce' ), sanitize_text_field( $status ) ) . $extra
                );
                break;
        }
    }
}
