<?php
/**
 * PixGo WooCommerce gateway.
 *
 * @package Pixgo_Payments_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce payment gateway implementation.
 */
class Pixgo_Payments_WC_Gateway extends WC_Payment_Gateway {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'pixgo_payments_wc';
		$this->method_title       = __( 'PixGo PIX', 'pixgo-payments-wc' );
		$this->method_description = __( 'Receba PIX via PixGo com QR Code e webhook assinado.', 'pixgo-payments-wc' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'PIX', 'pixgo-payments-wc' ) );
		$this->description = $this->get_option( 'description', __( 'Pague com PIX. A confirmacao e automatica.', 'pixgo-payments-wc' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	/**
	 * Defines gateway settings.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Ativar gateway', 'pixgo-payments-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Mostrar PixGo PIX no checkout', 'pixgo-payments-wc' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Titulo no checkout', 'pixgo-payments-wc' ),
				'type'        => 'text',
				'default'     => __( 'PIX', 'pixgo-payments-wc' ),
				'description' => __( 'Nome mostrado ao cliente.', 'pixgo-payments-wc' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'   => __( 'Descricao no checkout', 'pixgo-payments-wc' ),
				'type'    => 'textarea',
				'default' => __( 'Pague com PIX. A confirmacao e automatica apos o pagamento.', 'pixgo-payments-wc' ),
			),
			'api_key'          => array(
				'title'       => __( 'API Key', 'pixgo-payments-wc' ),
				'type'        => 'password',
				'description' => __( 'Chave privada da PixGo. Ela nunca aparece no checkout.', 'pixgo-payments-wc' ),
				'default'     => '',
			),
			'webhook_secret'   => array(
				'title'       => __( 'Webhook Secret', 'pixgo-payments-wc' ),
				'type'        => 'password',
				'description' => __( 'Segredo usado para validar a assinatura HMAC dos webhooks.', 'pixgo-payments-wc' ),
				'default'     => '',
			),
			'webhook_url'      => array(
				'title'       => __( 'URL do webhook', 'pixgo-payments-wc' ),
				'type'        => 'title',
				'description' => '<code class="pixgo-payments-wc-code">' . esc_html( Pixgo_Payments_WC_Webhook::get_url() ) . '</code>',
			),
			'paid_status'      => array(
				'title'       => __( 'Status quando pago', 'pixgo-payments-wc' ),
				'type'        => 'select',
				'default'     => 'processing',
				'options'     => $this->get_order_status_options(),
				'description' => __( 'Aplicado depois da confirmacao da PixGo.', 'pixgo-payments-wc' ),
			),
			'expired_status'   => array(
				'title'   => __( 'Status quando expirar', 'pixgo-payments-wc' ),
				'type'    => 'select',
				'default' => 'cancelled',
				'options' => $this->get_order_status_options(),
			),
			'refunded_status'  => array(
				'title'   => __( 'Status quando reembolsar', 'pixgo-payments-wc' ),
				'type'    => 'select',
				'default' => 'refunded',
				'options' => $this->get_order_status_options(),
			),
			'maximum_amount'   => array(
				'title'       => __( 'Valor maximo por pedido', 'pixgo-payments-wc' ),
				'type'        => 'price',
				'default'     => '',
				'description' => __( 'Opcional. Use se sua conta PixGo tiver limite por QR Code.', 'pixgo-payments-wc' ),
			),
			'confirm_with_api' => array(
				'title'   => __( 'Confirmacao reforcada', 'pixgo-payments-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Consultar a API PixGo antes de marcar pedido como pago', 'pixgo-payments-wc' ),
				'default' => 'no',
			),
			'debug'            => array(
				'title'   => __( 'Logs tecnicos', 'pixgo-payments-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ativar logs do WooCommerce', 'pixgo-payments-wc' ),
				'default' => 'no',
			),
			'new_order_status' => array(
				'title'       => __( 'Status ao gerar PIX', 'pixgo-payments-wc' ),
				'type'        => 'select',
				'default'     => 'on-hold',
				'options'     => $this->get_order_status_options(),
				'description' => __( 'Recomendado: aguardando. Isso mantem o pedido aberto sem marcar como pago antes da confirmacao.', 'pixgo-payments-wc' ),
			),
		);
	}

	/**
	 * Keeps saved password values when admin submits blank fields.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_password_field( $key, $value ) {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		return '' === trim( $value ) ? $this->get_option( $key ) : $value;
	}

	/**
	 * Sanitizes price fields.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_price_field( $key, $value ) {
		return wc_format_decimal( wp_unslash( (string) $value ) );
	}

	/**
	 * Checks whether gateway can be shown.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() || '' === trim( (string) $this->get_option( 'api_key' ) ) ) {
			return false;
		}

		$total = WC()->cart ? (float) WC()->cart->total : 0.0;

		if ( $total > 0 && $total < 10 ) {
			return false;
		}

		$maximum = $this->get_maximum_amount();
		return ! ( $maximum > 0 && $total > $maximum );
	}

	/**
	 * Creates PixGo payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Nao foi possivel iniciar o pagamento PixGo.', 'pixgo-payments-wc' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( (float) $order->get_total() < 10 ) {
			wc_add_notice( __( 'A PixGo aceita pagamentos a partir de R$ 10,00.', 'pixgo-payments-wc' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( $this->get_maximum_amount() > 0 && (float) $order->get_total() > $this->get_maximum_amount() ) {
			wc_add_notice( __( 'Este pedido esta acima do limite configurado para PixGo.', 'pixgo-payments-wc' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( $this->order_has_pending_pixgo_payment( $order ) ) {
			$this->schedule_status_checks( $order->get_id() );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$payload  = $this->build_payment_payload( $order );
		$response = $this->get_api()->create_payment( $payload );

		if ( is_wp_error( $response ) ) {
			$this->add_api_failure_note( $order, $response, $payload );
			wc_add_notice( __( 'Nao foi possivel gerar o PIX agora. Tente novamente.', 'pixgo-payments-wc' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$this->save_payment_data( $order, $data );
		$order->update_status( $this->get_configured_status( 'new_order_status', 'on-hold' ), __( 'PixGo: PIX gerado, aguardando pagamento.', 'pixgo-payments-wc' ) );
		$this->schedule_status_checks( $order->get_id() );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Shows QR Code/payment instructions after checkout.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $this->id !== $order->get_payment_method() ) {
			return;
		}

		$qr_code = (string) $order->get_meta( '_pixgo_qr_code', true );
		$qr_url  = esc_url( (string) $order->get_meta( '_pixgo_qr_image_url', true ) );

		if ( '' === $qr_code && '' === $qr_url ) {
			return;
		}

		$template = class_exists( 'Pixgo_Payments_WC_Templates' ) ? Pixgo_Payments_WC_Templates::render_order_template( $order ) : '';

		if ( '' !== trim( wp_strip_all_tags( $template ) ) ) {
			echo $template; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		echo '<section class="pixgo-payments-wc-payment" aria-labelledby="pixgo-payments-wc-title">';
		echo '<div class="pixgo-payments-wc-poller" hidden aria-hidden="true" data-pixgo-order-id="' . esc_attr( $order->get_id() ) . '" data-pixgo-order-key="' . esc_attr( $order->get_order_key() ) . '" data-pixgo-nonce="' . esc_attr( wp_create_nonce( 'pixgo_payments_wc_poll_' . $order->get_id() ) ) . '"></div>';
		echo '<div class="pixgo-payments-wc-copy">';
		echo '<p class="pixgo-payments-wc-kicker">' . esc_html__( 'Pagamento PIX', 'pixgo-payments-wc' ) . '</p>';
		echo '<h2 id="pixgo-payments-wc-title">' . esc_html__( 'Finalize pelo app do seu banco', 'pixgo-payments-wc' ) . '</h2>';
		echo '<p class="pixgo-payments-wc-status-text">' . esc_html__( 'Escaneie o QR Code ou copie o codigo PIX. O pagamento fica disponivel por ate 30 minutos e a confirmacao acontece automaticamente.', 'pixgo-payments-wc' ) . '</p>';
		echo '<div class="pixgo-payments-wc-steps" aria-label="' . esc_attr__( 'Passos para pagar com PIX', 'pixgo-payments-wc' ) . '">';
		echo '<span>' . esc_html__( '1. Abra seu banco', 'pixgo-payments-wc' ) . '</span>';
		echo '<span>' . esc_html__( '2. Escaneie ou copie', 'pixgo-payments-wc' ) . '</span>';
		echo '<span>' . esc_html__( '3. Aguarde a confirmacao', 'pixgo-payments-wc' ) . '</span>';
		echo '</div>';
		echo '<div class="pixgo-payments-wc-approved" hidden>';
		echo '<p class="pixgo-payments-wc-approved-kicker">' . esc_html__( 'Pagamento confirmado', 'pixgo-payments-wc' ) . '</p>';
		echo '<h3>' . esc_html__( 'Pedido aprovado', 'pixgo-payments-wc' ) . '</h3>';
		echo '<p>' . esc_html__( 'Seu pagamento foi confirmado e o pedido esta pronto para envio. Pode ficar tranquilo: agora e com a equipe da Psiloshop.', 'pixgo-payments-wc' ) . '</p>';
		echo '<p class="pixgo-payments-wc-approved-note">' . esc_html__( 'Assim que o pedido for despachado, voce recebera o codigo de rastreio por email.', 'pixgo-payments-wc' ) . '</p>';
		echo '</div>';

		if ( '' !== $qr_code ) {
			echo '<div class="pixgo-payments-wc-code-area">';
			echo '<label for="pixgo-payments-wc-code">' . esc_html__( 'PIX copia e cola', 'pixgo-payments-wc' ) . '</label>';
			echo '<textarea id="pixgo-payments-wc-code" readonly rows="4">' . esc_textarea( $qr_code ) . '</textarea>';
			echo '<button type="button" class="button pixgo-payments-wc-button" data-pixgo-copy="#pixgo-payments-wc-code">' . esc_html__( 'Copiar codigo PIX', 'pixgo-payments-wc' ) . '</button>';
			echo '</div>';
		}

		echo '</div>';

		if ( '' !== $qr_url ) {
			echo '<div class="pixgo-payments-wc-qr"><img src="' . $qr_url . '" alt="' . esc_attr__( 'QR Code PIX', 'pixgo-payments-wc' ) . '" loading="lazy"></div>';
		}

		echo '</section>';

		if ( function_exists( 'pixgo_payments_wc_render_alert_modal' ) ) {
			pixgo_payments_wc_render_alert_modal();
		}
	}

	/**
	 * Adds PIX code to pending customer emails.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $sent_to_admin Sent to admin.
	 * @param bool     $plain_text Plain text email.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || $this->id !== $order->get_payment_method() || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return;
		}

		$qr_code = (string) $order->get_meta( '_pixgo_qr_code', true );

		if ( '' === $qr_code ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'PIX copia e cola:', 'pixgo-payments-wc' ) . "\n" . esc_html( $qr_code ) . "\n";
			return;
		}

		echo '<h2>' . esc_html__( 'Pagamento PIX', 'pixgo-payments-wc' ) . '</h2>';
		echo '<pre style="white-space:pre-wrap;word-break:break-word;">' . esc_html( $qr_code ) . '</pre>';
	}

	/**
	 * Returns PixGo API client.
	 *
	 * @return Pixgo_Payments_WC_API
	 */
	public function get_api() {
		return new Pixgo_Payments_WC_API( $this->get_option( 'api_key' ), 'yes' === $this->get_option( 'debug', 'no' ) );
	}

	/**
	 * Gets webhook secret.
	 *
	 * @return string
	 */
	public function get_webhook_secret() {
		return trim( (string) $this->get_option( 'webhook_secret' ) );
	}

	/**
	 * Whether webhook payment should be confirmed with status API.
	 *
	 * @return bool
	 */
	public function should_confirm_with_api() {
		return 'yes' === $this->get_option( 'confirm_with_api', 'no' );
	}

	/**
	 * Returns configured WooCommerce status without wc- prefix.
	 *
	 * @param string $key Setting key.
	 * @param string $fallback Fallback status.
	 * @return string
	 */
	public function get_configured_status( $key, $fallback ) {
		return preg_replace( '/^wc-/', '', sanitize_key( $this->get_option( $key, $fallback ) ) );
	}

	/**
	 * Checks PixGo API and updates the order when needed.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $reschedule Whether to schedule another check if pending.
	 * @return bool
	 */
	public function sync_order_status_from_pixgo( WC_Order $order, $reschedule = false ) {
		$payment_id = (string) $order->get_meta( '_pixgo_payment_id', true );

		if ( '' === $payment_id ) {
			return false;
		}

		$response = $this->get_api()->get_payment_status( $payment_id );

		if ( is_wp_error( $response ) || empty( $response['data']['status'] ) ) {
			$order->add_order_note( __( 'PixGo: nao foi possivel consultar o status automaticamente.', 'pixgo-payments-wc' ) );
			$this->maybe_reschedule_status_check( $order, $reschedule );
			return false;
		}

		$data   = $response['data'];
		$status = sanitize_key( $data['status'] );

		if ( isset( $data['payment_id'] ) && ! hash_equals( $payment_id, sanitize_text_field( (string) $data['payment_id'] ) ) ) {
			$order->add_order_note( __( 'PixGo: consulta de status ignorada por payment_id divergente.', 'pixgo-payments-wc' ) );
			return false;
		}

		$this->save_payment_data( $order, $data );

		if ( 'completed' === $status ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $payment_id );
			}
			$this->clear_scheduled_status_checks( $order->get_id() );

			$target_status = $this->get_configured_status( 'paid_status', 'processing' );

			if ( $target_status !== $order->get_status() ) {
				$order->update_status( $target_status, __( 'PixGo: pagamento confirmado pela consulta de status.', 'pixgo-payments-wc' ) );
			} else {
				$order->add_order_note( __( 'PixGo: pagamento confirmado pela consulta de status.', 'pixgo-payments-wc' ) );
			}

			return true;
		}

		if ( 'expired' === $status ) {
			$order->update_status( $this->get_configured_status( 'expired_status', 'cancelled' ), __( 'PixGo: pagamento expirado pela consulta de status.', 'pixgo-payments-wc' ) );
			return true;
		}

		if ( 'cancelled' === $status ) {
			$order->update_status( 'cancelled', __( 'PixGo: pagamento cancelado pela consulta de status.', 'pixgo-payments-wc' ) );
			return true;
		}

		$this->maybe_reschedule_status_check( $order, $reschedule );
		return false;
	}

	/**
	 * Builds PixGo payment payload.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function build_payment_payload( WC_Order $order ) {
		$payload = array(
			'amount'           => (float) wc_format_decimal( $order->get_total(), 2 ),
			'description'      => $this->limit_text( 'Pedido ' . $order->get_order_number(), 200 ),
			'customer_name'    => $this->limit_text( $order->get_formatted_billing_full_name(), 100 ),
			'customer_address' => $this->limit_text( $this->get_billing_address( $order ), 500 ),
			'external_id'      => $this->limit_text( 'wc_' . $order->get_id() . '_' . $order->get_order_key(), 50 ),
			'webhook_url'      => Pixgo_Payments_WC_Webhook::get_url(),
			'expires_in'       => 1800,
		);

		$email = sanitize_email( (string) $order->get_billing_email() );

		if ( is_email( $email ) ) {
			$payload['customer_email'] = $this->limit_text( $email, 255 );
		}

		$phone = $this->get_customer_phone( $order );

		if ( '' !== $phone ) {
			$payload['customer_phone'] = $phone;
		}

		$document = $this->get_customer_document( $order );

		if ( '' !== $document ) {
			$payload['customer_cpf'] = $document;
		}

		return array_filter(
			$payload,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);
	}

	/**
	 * Reuses an already-created pending PixGo payment for the same order.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function order_has_pending_pixgo_payment( WC_Order $order ) {
		$payment_id = (string) $order->get_meta( '_pixgo_payment_id', true );
		$qr_code    = (string) $order->get_meta( '_pixgo_qr_code', true );
		$status     = sanitize_key( (string) $order->get_meta( '_pixgo_status', true ) );

		return '' !== $payment_id && '' !== $qr_code && in_array( $status, array( '', 'pending' ), true );
	}

	/**
	 * Adds a useful technical note when PixGo refuses payment creation.
	 *
	 * @param WC_Order $order Order.
	 * @param WP_Error $error Error.
	 * @param array    $payload Payload sent to PixGo.
	 */
	private function add_api_failure_note( WC_Order $order, WP_Error $error, array $payload ) {
		$error_data = $error->get_error_data();
		$details    = is_array( $error_data ) ? wp_json_encode( $error_data ) : '';
		$safe_keys  = implode( ', ', array_keys( $payload ) );

		if ( $details && strlen( $details ) > 900 ) {
			$details = substr( $details, 0, 900 ) . '...';
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: error message, 2: payload keys, 3: API details. */
				__( 'PixGo: falha ao criar PIX. Mensagem: %1$s. Campos enviados: %2$s. Detalhes: %3$s', 'pixgo-payments-wc' ),
				$error->get_error_message(),
				$safe_keys,
				$details ? $details : '-'
			)
		);
	}

	/**
	 * Saves selected PixGo response data.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data PixGo response data.
	 */
	private function save_payment_data( WC_Order $order, array $data ) {
		$map = array(
			'payment_id'   => '_pixgo_payment_id',
			'status'       => '_pixgo_status',
			'qr_code'      => '_pixgo_qr_code',
			'qr_image_url' => '_pixgo_qr_image_url',
			'expires_at'   => '_pixgo_expires_at',
			'created_at'   => '_pixgo_created_at',
		);

		foreach ( $map as $source => $meta_key ) {
			if ( isset( $data[ $source ] ) ) {
				$order->update_meta_data( $meta_key, wc_clean( $data[ $source ] ) );
			}
		}

		$order->save();
	}

	/**
	 * Schedules fallback checks for the PixGo payment status.
	 *
	 * @param int $order_id Order ID.
	 */
	private function schedule_status_checks( $order_id ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		$this->clear_scheduled_status_checks( $order_id );

		foreach ( array( 30, 60, 120, 180, 300, 600, 900, 1200, 1500, 1800 ) as $delay ) {
			$args = array( absint( $order_id ) );
			wp_schedule_single_event( time() + $delay, 'pixgo_payments_wc_check_order_status', $args );
		}
	}

	/**
	 * Schedules one more check when the payment is still pending.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $enabled Whether rescheduling is enabled.
	 */
	private function maybe_reschedule_status_check( WC_Order $order, $enabled ) {
		if ( $enabled && ! $order->is_paid() && function_exists( 'wp_schedule_single_event' ) ) {
			$args = array( $order->get_id() );

			if ( ! wp_next_scheduled( 'pixgo_payments_wc_check_order_status', $args ) ) {
				wp_schedule_single_event( time() + 300, 'pixgo_payments_wc_check_order_status', $args );
			}
		}
	}

	/**
	 * Clears pending fallback checks once the order is paid.
	 *
	 * @param int $order_id Order ID.
	 */
	private function clear_scheduled_status_checks( $order_id ) {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'pixgo_payments_wc_check_order_status', array( absint( $order_id ) ) );
		}
	}

	/**
	 * Gets order status options.
	 *
	 * @return array
	 */
	private function get_order_status_options() {
		$options = array();

		foreach ( wc_get_order_statuses() as $status => $label ) {
			$options[ preg_replace( '/^wc-/', '', $status ) ] = $label;
		}

		return $options;
	}

	/**
	 * Gets optional maximum amount.
	 *
	 * @return float
	 */
	private function get_maximum_amount() {
		$value = $this->get_option( 'maximum_amount', '' );
		return '' === $value ? 0.0 : (float) wc_format_decimal( $value );
	}

	/**
	 * Formats billing address.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function get_billing_address( WC_Order $order ) {
		$parts = array(
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
			$order->get_billing_city(),
			$order->get_billing_state(),
			$order->get_billing_postcode(),
			$order->get_billing_country(),
		);

		return implode( ', ', array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * Reads CPF/CNPJ from common checkout fields.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function get_customer_document( WC_Order $order ) {
		$fields = array( '_billing_cpf', 'billing_cpf', '_billing_cnpj', 'billing_cnpj' );

		foreach ( $fields as $field ) {
			$digits = preg_replace( '/\D+/', '', (string) $order->get_meta( $field, true ) );

			if ( 11 === strlen( $digits ) || 14 === strlen( $digits ) ) {
				return $digits;
			}
		}

		return '';
	}

	/**
	 * Gets a PixGo-friendly phone number.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function get_customer_phone( WC_Order $order ) {
		$digits = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );

		if ( strlen( $digits ) < 10 ) {
			return '';
		}

		return substr( $digits, 0, 20 );
	}

	/**
	 * Limits text length.
	 *
	 * @param string $text Text.
	 * @param int    $limit Max characters.
	 * @return string
	 */
	private function limit_text( $text, $limit ) {
		$text = wp_strip_all_tags( (string) $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
	}
}
