<?php
/**
 * PixGo webhook handler.
 *
 * @package Pixgo_Payments_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles signed PixGo webhook requests.
 */
class Pixgo_Payments_WC_Webhook {
	const ENDPOINT = 'pixgo_payments_wc_webhook';
	const MAX_SKEW = 900;

	/**
	 * Registers WooCommerce API endpoint.
	 */
	public static function init() {
		add_action( 'woocommerce_api_' . self::ENDPOINT, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Returns public webhook URL.
	 *
	 * @return string
	 */
	public static function get_url() {
		return add_query_arg( 'wc-api', self::ENDPOINT, home_url( '/' ) );
	}

	/**
	 * Handles the webhook.
	 */
	public static function handle() {
		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			self::respond( 405, array( 'error' => 'method_not_allowed' ) );
		}

		$gateway = self::get_gateway();

		if ( ! $gateway ) {
			self::respond( 503, array( 'error' => 'gateway_unavailable' ) );
		}

		$secret = $gateway->get_webhook_secret();

		if ( '' === $secret ) {
			self::respond( 401, array( 'error' => 'webhook_secret_missing' ) );
		}

		$payload      = (string) file_get_contents( 'php://input' );
		$timestamp    = self::get_header( 'X-Webhook-Timestamp' );
		$signature    = self::normalize_signature( self::get_header( 'X-Webhook-Signature' ) );
		$header_event = sanitize_key( self::get_header( 'X-Webhook-Event' ) );

		if ( ! self::verify_signature( $payload, $timestamp, $signature, $secret ) ) {
			self::log( 'Webhook rejected: invalid signature or timestamp.', 'warning', $gateway );
			self::respond( 401, array( 'error' => 'invalid_signature' ) );
		}

		$body = json_decode( $payload, true );

		if ( ! is_array( $body ) || empty( $body['event'] ) || empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			self::log( 'Webhook rejected: invalid payload.', 'warning', $gateway );
			self::respond( 400, array( 'error' => 'invalid_payload' ) );
		}

		$event = sanitize_key( $body['event'] );
		$data  = $body['data'];

		if ( '' !== $header_event && $header_event !== $event ) {
			self::log( 'Webhook rejected: event mismatch.', 'warning', $gateway );
			self::respond( 400, array( 'error' => 'event_mismatch' ) );
		}

		$order = self::find_order( $data );

		if ( ! $order || 'pixgo_payments_wc' !== $order->get_payment_method() ) {
			self::log( 'Webhook rejected: order not found.', 'warning', $gateway );
			self::respond( 404, array( 'error' => 'order_not_found' ) );
		}

		if ( ! self::payment_id_matches( $data, $order ) ) {
			self::log( 'Webhook rejected: payment_id mismatch.', 'warning', $gateway );
			self::respond( 409, array( 'error' => 'payment_id_mismatch' ) );
		}

		self::store_meta( $order, $data );
		self::apply_event( $event, $data, $order, $gateway );
		self::respond( 200, array( 'received' => true ) );
	}

	/**
	 * Verifies HMAC-SHA256 signature.
	 *
	 * @param string $payload Raw request body.
	 * @param string $timestamp Timestamp header.
	 * @param string $signature Signature header.
	 * @param string $secret Webhook secret.
	 * @return bool
	 */
	private static function verify_signature( $payload, $timestamp, $signature, $secret ) {
		if ( '' === $payload || '' === $timestamp || '' === $signature || ! ctype_digit( (string) $timestamp ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Reads HTTP headers across Apache, Nginx, CGI and FastCGI setups.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	private static function get_header( $name ) {
		$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );

		if ( isset( $_SERVER[ $server_key ] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) );
		}

		$redirect_key = 'REDIRECT_' . $server_key;

		if ( isset( $_SERVER[ $redirect_key ] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER[ $redirect_key ] ) );
		}

		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();

			foreach ( $headers as $header_name => $value ) {
				if ( strtolower( $header_name ) === strtolower( $name ) ) {
					return sanitize_text_field( wp_unslash( $value ) );
				}
			}
		}

		return '';
	}

	/**
	 * Normalizes signature header formats.
	 *
	 * @param string $signature Signature.
	 * @return string
	 */
	private static function normalize_signature( $signature ) {
		$signature = trim( (string) $signature );

		if ( 0 === strpos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}

		return strtolower( $signature );
	}

	/**
	 * Applies PixGo event to order.
	 *
	 * @param string                    $event PixGo event.
	 * @param array                     $data PixGo data.
	 * @param WC_Order                  $order Order.
	 * @param Pixgo_Payments_WC_Gateway $gateway Gateway.
	 */
	private static function apply_event( $event, array $data, WC_Order $order, Pixgo_Payments_WC_Gateway $gateway ) {
		$payment_id = isset( $data['payment_id'] ) ? sanitize_text_field( (string) $data['payment_id'] ) : '';

		if ( 'payment.completed' === $event ) {
			if ( '' === $payment_id || ! self::amount_matches_order( $data, $order ) ) {
				$order->add_order_note( __( 'PixGo: webhook completed recusado por dados divergentes.', 'pixgo-payments-wc' ) );
				return;
			}

			if ( $gateway->should_confirm_with_api() && ! self::api_confirms_completed( $payment_id, $gateway ) ) {
				$order->add_order_note( __( 'PixGo: webhook assinado recebido como completed, mas a consulta extra da API nao confirmou no mesmo instante. Pedido baixado pelo webhook assinado.', 'pixgo-payments-wc' ) );
			}

			if ( ! $order->is_paid() ) {
				$order->payment_complete( $payment_id );
			}
			self::clear_scheduled_status_checks( $order->get_id() );

			$target_status = $gateway->get_configured_status( 'paid_status', 'processing' );

			if ( $target_status !== $order->get_status() ) {
				$order->update_status( $target_status, __( 'PixGo: pagamento confirmado.', 'pixgo-payments-wc' ) );
			} else {
				$order->add_order_note( __( 'PixGo: pagamento confirmado.', 'pixgo-payments-wc' ) );
			}

			return;
		}

		if ( 'payment.expired' === $event ) {
			$order->update_status( $gateway->get_configured_status( 'expired_status', 'cancelled' ), __( 'PixGo: pagamento expirado.', 'pixgo-payments-wc' ) );
			return;
		}

		if ( 'payment.refunded' === $event ) {
			$order->update_status( $gateway->get_configured_status( 'refunded_status', 'refunded' ), __( 'PixGo: pagamento reembolsado.', 'pixgo-payments-wc' ) );
		}
	}

	/**
	 * Confirms payment status with PixGo API.
	 *
	 * @param string                    $payment_id PixGo payment ID.
	 * @param Pixgo_Payments_WC_Gateway $gateway Gateway.
	 * @return bool
	 */
	private static function api_confirms_completed( $payment_id, Pixgo_Payments_WC_Gateway $gateway ) {
		$response = $gateway->get_api()->get_payment_status( $payment_id );
		return ! is_wp_error( $response ) && isset( $response['data']['status'] ) && 'completed' === sanitize_key( $response['data']['status'] );
	}

	/**
	 * Finds order from webhook data.
	 *
	 * @param array $data PixGo data.
	 * @return WC_Order|false
	 */
	private static function find_order( array $data ) {
		$external_id = isset( $data['external_id'] ) ? sanitize_text_field( (string) $data['external_id'] ) : '';

		if ( preg_match( '/^wc_(\d+)_/', $external_id, $matches ) ) {
			$order = wc_get_order( absint( $matches[1] ) );

			if ( $order ) {
				return $order;
			}
		}

		$payment_id = isset( $data['payment_id'] ) ? sanitize_text_field( (string) $data['payment_id'] ) : '';

		if ( '' === $payment_id ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_pixgo_payment_id',
				'meta_value' => $payment_id,
				'return'     => 'objects',
			)
		);

		return empty( $orders ) ? false : $orders[0];
	}

	/**
	 * Ensures payment ID belongs to the order.
	 *
	 * @param array    $data PixGo data.
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function payment_id_matches( array $data, WC_Order $order ) {
		$stored   = (string) $order->get_meta( '_pixgo_payment_id', true );
		$incoming = isset( $data['payment_id'] ) ? sanitize_text_field( (string) $data['payment_id'] ) : '';

		return '' === $stored || ( '' !== $incoming && hash_equals( $stored, $incoming ) );
	}

	/**
	 * Ensures webhook amount matches order total.
	 *
	 * @param array    $data PixGo data.
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function amount_matches_order( array $data, WC_Order $order ) {
		if ( isset( $data['amount'] ) ) {
			$amount = (float) wc_format_decimal( $data['amount'] );
		} elseif ( isset( $data['amounts']['gross'] ) ) {
			$amount = (float) wc_format_decimal( $data['amounts']['gross'] );
		} else {
			return false;
		}

		return abs( (float) $order->get_total() - $amount ) < 0.01;
	}

	/**
	 * Stores safe webhook metadata.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data PixGo data.
	 */
	private static function store_meta( WC_Order $order, array $data ) {
		$allowed = array( 'payment_id', 'status', 'completed_at', 'expired_at', 'refunded_at' );

		foreach ( $allowed as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$order->update_meta_data( '_pixgo_' . $field, wc_clean( $data[ $field ] ) );
			}
		}

		$order->save();
	}

	/**
	 * Gets PixGo gateway instance.
	 *
	 * @return Pixgo_Payments_WC_Gateway|null
	 */
	private static function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['pixgo_payments_wc'] ) && $gateways['pixgo_payments_wc'] instanceof Pixgo_Payments_WC_Gateway ? $gateways['pixgo_payments_wc'] : null;
	}

	/**
	 * Clears pending fallback checks once the webhook confirms payment.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function clear_scheduled_status_checks( $order_id ) {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'pixgo_payments_wc_check_order_status', array( absint( $order_id ) ) );
		}
	}

	/**
	 * Logs webhook events when debug is enabled.
	 *
	 * @param string                    $message Message.
	 * @param string                    $level Log level.
	 * @param Pixgo_Payments_WC_Gateway $gateway Gateway.
	 */
	private static function log( $message, $level, Pixgo_Payments_WC_Gateway $gateway ) {
		if ( 'yes' === $gateway->get_option( 'debug', 'no' ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'pixgo-payments-wc' ) );
		}
	}

	/**
	 * Sends response.
	 *
	 * @param int   $status HTTP status.
	 * @param array $body Response body.
	 */
	private static function respond( $status, array $body ) {
		wp_send_json( $body, $status );
	}
}
