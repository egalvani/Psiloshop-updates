<?php
/**
 * PixGo API wrapper.
 *
 * @package Pixgo_Payments_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles server-to-server PixGo API calls.
 */
class Pixgo_Payments_WC_API {
	const BASE_URL = 'https://pixgo.org/api/v1';

	/**
	 * PixGo API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Debug flag.
	 *
	 * @var bool
	 */
	private $debug;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 * @param bool   $debug Debug flag.
	 */
	public function __construct( $api_key, $debug = false ) {
		$this->api_key = trim( (string) $api_key );
		$this->debug   = (bool) $debug;
	}

	/**
	 * Creates a PixGo payment.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error
	 */
	public function create_payment( array $payload ) {
		return $this->request( 'POST', '/payment/create', $payload );
	}

	/**
	 * Gets PixGo payment status.
	 *
	 * @param string $payment_id PixGo payment ID.
	 * @return array|WP_Error
	 */
	public function get_payment_status( $payment_id ) {
		$payment_id = rawurlencode( sanitize_text_field( (string) $payment_id ) );
		return $this->request( 'GET', '/payment/' . $payment_id . '/status' );
	}

	/**
	 * Sends a request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path API path.
	 * @param array|null $payload Optional payload.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $payload = null ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'pixgo_missing_api_key', __( 'API Key PixGo ausente.', 'pixgo-payments-wc' ) );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'X-API-Key'   => $this->api_key,
			),
		);

		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'HTTP error: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = (string) wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'pixgo_invalid_response', __( 'Resposta invalida da PixGo.', 'pixgo-payments-wc' ) );
		}

		if ( $status_code < 200 || $status_code >= 300 || empty( $data['success'] ) ) {
			$message = isset( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : __( 'A PixGo recusou a solicitacao.', 'pixgo-payments-wc' );
			$this->log( 'API error: ' . $message, 'error' );
			$data['http_status'] = $status_code;
			return new WP_Error( 'pixgo_api_error', $message, $data );
		}

		return $data;
	}

	/**
	 * Writes safe technical logs.
	 *
	 * @param string $message Message.
	 * @param string $level Log level.
	 */
	private function log( $message, $level = 'info' ) {
		if ( $this->debug && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'pixgo-payments-wc' ) );
		}
	}
}
