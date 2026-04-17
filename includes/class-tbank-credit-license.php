<?php
defined( 'ABSPATH' ) || exit;

/**
 * TBank_Credit_License — управление лицензией плагина «Т-Банк Рассрочка и Кредит».
 *
 * Активация производится через REST API плагина-менеджера ключей,
 * установленного на сайте разработчика (рукодер.рф).
 *
 * @author  Сергей Солошенко (RuCoder) — https://рукодер.рф/
 * @version 1.0.0
 */
class TBank_Credit_License {

	const KEY_OPTION      = 'tbank_credit_license_key';
	const STATUS_OPTION   = 'tbank_credit_license_status';
	const DOMAIN_OPTION   = 'tbank_credit_license_domain';
	const ATTEMPTS_OPTION = 'tbank_credit_license_attempts';
	const MAX_ATTEMPTS    = 5;
	const KEY_PREFIX      = 'TBCW';
	const KEY_REGEX       = '/^TBCW-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/';
	const API_VALIDATE    = 'https://рукодер.рф/wp-json/tbc-keys/v1/validate';
	const API_DEACTIVATE  = 'https://рукодер.рф/wp-json/tbc-keys/v1/deactivate';
	const ADMIN_PAGE      = 'tbank-credit-activate';
	const REQUEST_EMAIL   = 'rucoder.rf@yandex.ru';
	const PLUGIN_PRICE    = '2 200 рублей';

	// ─── Status checks ────────────────────────────────────────────────────────

	public static function is_active(): bool {
		return get_option( self::STATUS_OPTION ) === 'active';
	}

	public static function get_key(): string {
		return (string) get_option( self::KEY_OPTION, '' );
	}

	public static function get_domain(): string {
		$saved = get_option( self::DOMAIN_OPTION, '' );
		return $saved ?: (string) parse_url( home_url(), PHP_URL_HOST );
	}

	public static function get_attempts(): int {
		$val = get_option( self::ATTEMPTS_OPTION, null );
		if ( $val === null ) {
			update_option( self::ATTEMPTS_OPTION, self::MAX_ATTEMPTS );
			return self::MAX_ATTEMPTS;
		}
		return max( 0, (int) $val );
	}

	// ─── Activate ─────────────────────────────────────────────────────────────

	public static function activate( string $raw_key ): true|WP_Error {
		$key = strtoupper( trim( $raw_key ) );

		if ( ! preg_match( self::KEY_REGEX, $key ) ) {
			return new WP_Error(
				'format',
				'Неверный формат ключа. Используйте формат: TBCW-XXXX-XXXX-XXXX-XXXX'
			);
		}

		$attempts = self::get_attempts();
		if ( $attempts <= 0 ) {
			return new WP_Error(
				'no_attempts',
				'Исчерпан лимит попыток активации. Обратитесь к разработчику: ' . self::REQUEST_EMAIL
			);
		}

		update_option( self::ATTEMPTS_OPTION, $attempts - 1 );

		$domain   = (string) parse_url( home_url(), PHP_URL_HOST );
		$response = wp_remote_post( self::API_VALIDATE, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'key' => $key, 'domain' => $domain ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'connection',
				'Ошибка соединения с сервером активации: ' . $response->get_error_message() .
				'. Проверьте интернет-соединение хостинга.'
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 200 && ! empty( $body['valid'] ) ) {
			update_option( self::KEY_OPTION, $key );
			update_option( self::STATUS_OPTION, 'active' );
			update_option( self::DOMAIN_OPTION, $domain );
			update_option( self::ATTEMPTS_OPTION, self::MAX_ATTEMPTS );
			return true;
		}

		$msg = ! empty( $body['message'] )
			? $body['message']
			: 'Ключ не найден или уже используется на другом домене.';

		return new WP_Error( 'invalid', $msg );
	}

	// ─── Deactivate ───────────────────────────────────────────────────────────

	public static function deactivate(): void {
		$key    = self::get_key();
		$domain = self::get_domain();

		if ( $key ) {
			wp_remote_post( self::API_DEACTIVATE, array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'key' => $key, 'domain' => $domain ) ),
			) );
		}

		update_option( self::STATUS_OPTION, '' );
		update_option( self::KEY_OPTION, '' );
		update_option( self::DOMAIN_OPTION, '' );
	}

	// ─── License request email ────────────────────────────────────────────────

	public static function send_license_request( array $post ): true|WP_Error {
		$name         = sanitize_text_field( $post['req_name'] ?? '' );
		$email        = sanitize_email( $post['req_email'] ?? '' );
		$contact      = sanitize_text_field( $post['req_contact'] ?? '' );
		$consent_data = ! empty( $post['consent_data'] );
		$consent_promo= ! empty( $post['consent_promo'] );

		if ( empty( $name ) || empty( $email ) || empty( $contact ) ) {
			return new WP_Error( 'missing', 'Заполните все обязательные поля.' );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'email', 'Укажите корректный email-адрес.' );
		}
		if ( ! $consent_data ) {
			return new WP_Error( 'consent', 'Необходимо дать согласие на обработку персональных данных.' );
		}

		$domain  = (string) parse_url( home_url(), PHP_URL_HOST );
		$site    = home_url();
		$date    = current_time( 'd.m.Y H:i:s' );

		$subject = 'Запрос лицензии tbank-credit-woocommerce';
		$message  = "=== НОВЫЙ ЗАПРОС ЛИЦЕНЗИИ tbank-credit-woocommerce ===\n\n";
		$message .= "Имя: {$name}\n";
		$message .= "Email: {$email}\n";
		$message .= "Связь: {$contact}\n";
		$message .= "Сайт: {$site}\n";
		$message .= "Домен: {$domain}\n\n";
		$message .= "Согласие на обработку данных: " . ( $consent_data ? 'Да' : 'Нет' ) . "\n";
		$message .= "Согласие на рассылку: " . ( $consent_promo ? 'Да' : 'Нет' ) . "\n";
		$message .= "Дата запроса: {$date}\n\n";
		$message .= "=== Выдайте ключ на https://рукодер.рф/wp-admin/admin.php?page=tbank-credit-keys ===";

		$headers = array( 'Reply-To: ' . $name . ' <' . $email . '>' );

		if ( ! wp_mail( self::REQUEST_EMAIL, $subject, $message, $headers ) ) {
			return new WP_Error( 'mail', 'Не удалось отправить запрос. Пожалуйста, напишите напрямую: ' . self::REQUEST_EMAIL );
		}

		return true;
	}

	// ─── Admin page registration ──────────────────────────────────────────────

	public static function register_admin_pages(): void {
		add_menu_page(
			'Активация Т-Банк Рассрочка',
			'Т-Банк Лицензия',
			'manage_options',
			self::ADMIN_PAGE,
			array( self::class, 'render_activation_page' ),
			'dashicons-lock',
			56
		);
	}

	// ─── Activation page HTML ─────────────────────────────────────────────────

	public static function render_activation_page(): void {
		$notice    = '';
		$is_active = self::is_active();

		if ( isset( $_POST['tbank_deactivate'] ) ) {
			check_admin_referer( 'tbank_deactivate' );
			self::deactivate();
			$is_active = false;
			$notice    = '<div class="notice notice-success is-dismissible"><p>✅ Лицензия деактивирована.</p></div>';
		}

		if ( isset( $_POST['tbank_activate'] ) ) {
			check_admin_referer( 'tbank_activate' );
			$key    = sanitize_text_field( $_POST['license_key'] ?? '' );
			$result = self::activate( $key );
			if ( is_wp_error( $result ) ) {
				$notice = '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				$is_active = true;
				$notice    = '<div class="notice notice-success is-dismissible"><p>✅ Плагин успешно активирован! Все функции доступны.</p></div>';
			}
		}

		if ( isset( $_POST['tbank_request_license'] ) ) {
			check_admin_referer( 'tbank_request_license' );
			$result = self::send_license_request( $_POST );
			if ( is_wp_error( $result ) ) {
				$notice = '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				$notice = '<div class="notice notice-success is-dismissible"><p>✅ Запрос отправлен! Мы свяжемся с вами в ближайшее время на указанный email.</p></div>';
			}
		}

		$attempts  = self::get_attempts();
		$key_val   = self::get_key();
		$act_url   = admin_url( 'admin.php?page=' . self::ADMIN_PAGE );
		?>
		<div class="wrap" style="max-width:760px;">
			<?php echo $notice; ?>

			<h1 style="display:flex;align-items:center;gap:10px;">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="28" height="28" aria-hidden="true"><rect width="32" height="32" rx="6" fill="#1a1a1a"/><text x="16" y="23" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="900" font-size="20" fill="#FFDD2D">Т</text></svg>
				Т-Банк Рассрочка и Кредит — Активация плагина
			</h1>

			<?php if ( $is_active ) : ?>
				<div style="background:#f0fff4;border:1px solid #6fcf97;border-radius:8px;padding:24px;margin:20px 0;">
					<h2 style="margin-top:0;color:#1a6b2b;">✅ Плагин активирован</h2>
					<table class="form-table" style="margin:0;">
						<tr>
							<th style="width:140px;">Ключ:</th>
							<td><code style="font-size:14px;"><?php echo esc_html( $key_val ); ?></code></td>
						</tr>
						<tr>
							<th>Домен:</th>
							<td><code><?php echo esc_html( self::get_domain() ); ?></code></td>
						</tr>
						<tr>
							<th>Статус:</th>
							<td><strong style="color:#1a6b2b;">Активна</strong></td>
						</tr>
					</table>
					<hr style="margin:16px 0;">
					<form method="post">
						<?php wp_nonce_field( 'tbank_deactivate' ); ?>
						<input type="submit" name="tbank_deactivate" class="button button-secondary"
							value="🔓 Деактивировать лицензию"
							onclick="return confirm('Вы уверены? Настройки плагина будут заблокированы до повторной активации.');">
					</form>
				</div>

			<?php else : ?>

				<p>Для использования всех функций <strong>Т-Банк Рассрочка и Кредит</strong> введите лицензионный ключ. Если у вас нет ключа — запросите его ниже.</p>

				<!-- Форма активации -->
				<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin:20px 0;">
					<h2 style="margin-top:0;">🔑 Ввести лицензионный ключ</h2>
					<form method="post" autocomplete="off">
						<?php wp_nonce_field( 'tbank_activate' ); ?>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="license_key">Лицензионный ключ</label></th>
								<td>
									<input
										type="text"
										id="license_key"
										name="license_key"
										class="regular-text"
										placeholder="TBCW-XXXX-XXXX-XXXX-XXXX"
										maxlength="24"
										style="font-family:monospace;font-size:14px;letter-spacing:1px;"
										required
									>
									<p class="description">
										Формат ключа: <code>TBCW-XXXX-XXXX-XXXX-XXXX</code>.
										Осталось попыток: <strong><?php echo esc_html( $attempts ); ?></strong> из <?php echo self::MAX_ATTEMPTS; ?>.
									</p>
								</td>
							</tr>
						</table>
						<?php submit_button( 'Активировать', 'primary large', 'tbank_activate' ); ?>
					</form>
				</div>

				<!-- Форма запроса ключа -->
				<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin:20px 0;">
					<h2 style="margin-top:0;">📨 Запросить лицензионный ключ</h2>
					<p>Нет ключа? Заполните форму — владелец плагина рассмотрит запрос и пришлёт ключ на ваш email.</p>
					<form method="post">
						<?php wp_nonce_field( 'tbank_request_license' ); ?>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="req_name">Ваше имя <span style="color:#d63638;">*</span></label></th>
								<td><input type="text" id="req_name" name="req_name" class="regular-text" placeholder="Иван Иванов" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="req_email">Ваш email <span style="color:#d63638;">*</span></label></th>
								<td>
									<input type="email" id="req_email" name="req_email" class="regular-text" placeholder="your@email.ru" required>
									<p class="description">На этот адрес придёт лицензионный ключ.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="req_contact">Ссылка для связи <span style="color:#d63638;">*</span></label></th>
								<td>
									<input type="text" id="req_contact" name="req_contact" class="regular-text" placeholder="Например: https://t.me/username или https://vk.com/id..." required>
									<p class="description">Ссылка на Telegram, ВКонтакте или другой удобный способ связи.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Согласия <span style="color:#d63638;">*</span></th>
								<td>
									<label style="display:block;margin-bottom:10px;">
										<input type="checkbox" name="consent_data" value="1" required>
										Даю своё согласие на <a href="https://рукодер.рф/" target="_blank">обработку персональных данных</a>.
									</label>
									<label style="display:block;">
										<input type="checkbox" name="consent_promo" value="1">
										Согласен(а) получать информационные рассылки и уведомления об акциях на указанный email. Подтверждаю, что могу отменить подписку в любое время.
									</label>
								</td>
							</tr>
						</table>
						<?php submit_button( 'Отправить запрос', 'secondary large', 'tbank_request_license' ); ?>
					</form>
				</div>

				<!-- Информация о лицензии -->
				<div style="background:#f9f9f9;border:1px solid #eee;border-radius:8px;padding:24px;margin:20px 0;">
					<h2 style="margin-top:0;">ℹ️ Информация о лицензии и активации плагина</h2>
					<p>Плагин распространяется под лицензией <strong>GPL v2 or later</strong>. Функционал плагина становится доступен только после активации с использованием уникального ключа.</p>

					<h3>Как получить ключ активации</h3>
					<p>Чтобы получить ключ активации, вы можете выбрать один из двух способов:</p>

					<h4>Способ 1. Заполнить форму</h4>
					<p>Заполните короткую форму выше — и мы оперативно свяжемся с вами для завершения покупки. После отправки формы мы свяжемся с вами, предоставим реквизиты для оплаты и ответим на любые вопросы.</p>

					<h4>Способ 2. Написать напрямую</h4>
					<p>Если вы предпочитаете сразу обсудить покупку, просто напишите нам одним из удобных способов:</p>
					<ul>
						<li>Сайт: <a href="https://рукодер.рф/" target="_blank">https://рукодер.рф/</a></li>
						<li>Telegram: <a href="https://t.me/RussCoder" target="_blank">https://t.me/RussCoder</a></li>
						<li>ВКонтакте: <a href="https://vk.com/ruscoder" target="_blank">https://vk.com/ruscoder</a></li>
					</ul>

					<h3>Стоимость и условия</h3>
					<ul>
						<li>Стоимость плагина: <strong><?php echo esc_html( self::PLUGIN_PRICE ); ?></strong>.</li>
						<li>Тип лицензии: <strong>бессрочная версия</strong>.</li>
						<li>Обновления: <strong>все будущие обновления включены в стоимость</strong>.</li>
					</ul>
					<p>После подтверждения оплаты мы незамедлительно отправим вам уникальный ключ активации — вы сможете сразу активировать плагин и начать им пользоваться.</p>
				</div>

			<?php endif; ?>
		</div>
		<?php
	}
}
