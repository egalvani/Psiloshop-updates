<?php
/**
 * PixGo editable templates and shortcodes.
 *
 * @package Pixgo_Payments_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides Elementor-friendly shortcodes and template rendering.
 */
class Pixgo_Payments_WC_Templates {
	/**
	 * Registers shortcodes.
	 */
	public static function init() {
		add_shortcode( 'pixgo_qr_code', array( __CLASS__, 'shortcode_qr_code' ) );
		add_shortcode( 'pixgo_pix_code', array( __CLASS__, 'shortcode_pix_code' ) );
		add_shortcode( 'pixgo_copy_button', array( __CLASS__, 'shortcode_copy_button' ) );
		add_shortcode( 'pixgo_payment_status', array( __CLASS__, 'shortcode_payment_status' ) );
		add_shortcode( 'pixgo_approved_message', array( __CLASS__, 'shortcode_approved_message' ) );
		add_shortcode( 'pixgo_order_number', array( __CLASS__, 'shortcode_order_number' ) );
		add_shortcode( 'pixgo_order_total', array( __CLASS__, 'shortcode_order_total' ) );
	}

	/**
	 * Returns current PixGo order in template context.
	 *
	 * @return WC_Order|null
	 */
	public static function get_current_order() {
		global $pixgo_payments_wc_current_order;
		return $pixgo_payments_wc_current_order instanceof WC_Order ? $pixgo_payments_wc_current_order : null;
	}

	/**
	 * Renders selected page template for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function render_order_template( WC_Order $order ) {
		$page_id = absint( get_option( 'pixgo_payments_wc_template_page_id', 0 ) );
		$page    = $page_id ? get_post( $page_id ) : null;

		if ( ! $page || 'trash' === $page->post_status ) {
			return '';
		}

		global $post, $pixgo_payments_wc_current_order;
		$previous_post  = $post;
		$previous_order = $pixgo_payments_wc_current_order;

		$post                            = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$pixgo_payments_wc_current_order = $order;
		setup_postdata( $post );

		$content = apply_filters( 'the_content', $page->post_content );

		wp_reset_postdata();
		$post                            = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$pixgo_payments_wc_current_order = $previous_order;

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return '';
		}

		$poller = '<div class="pixgo-payments-wc-poller" hidden aria-hidden="true" data-pixgo-order-id="' . esc_attr( $order->get_id() ) . '" data-pixgo-order-key="' . esc_attr( $order->get_order_key() ) . '" data-pixgo-nonce="' . esc_attr( wp_create_nonce( 'pixgo_payments_wc_poll_' . $order->get_id() ) ) . '"></div>';

		$modal = '';

		if ( function_exists( 'pixgo_payments_wc_render_alert_modal' ) ) {
			ob_start();
			pixgo_payments_wc_render_alert_modal();
			$modal = ob_get_clean();
		}

		return '<section class="pixgo-payments-wc-template">' . $poller . $content . '</section>' . $modal;
	}

	/**
	 * Creates or updates the default page with PixGo shortcodes.
	 *
	 * @return int
	 */
	public static function create_default_page() {
		$existing_id = absint( get_option( 'pixgo_payments_wc_template_page_id', 0 ) );
		$existing    = $existing_id ? get_post( $existing_id ) : null;
		$post_data   = array(
			'post_title'   => 'Pagamento PixGo - Psiloshop',
			'post_name'    => 'pagamento-pixgo-psiloshop',
			'post_type'    => 'page',
			'post_status'  => 'private',
			'post_content' => self::get_default_template_content(),
		);

		if ( $existing && 'trash' !== $existing->post_status ) {
			$post_data['ID'] = $existing_id;
		}

		$page_id = wp_insert_post( $post_data );

		if ( ! is_wp_error( $page_id ) ) {
			update_post_meta( $page_id, '_pixgo_payments_wc_template', 'yes' );
		}

		return is_wp_error( $page_id ) ? 0 : absint( $page_id );
	}

	/**
	 * QR Code shortcode.
	 *
	 * @return string
	 */
	public static function shortcode_qr_code() {
		$order = self::get_current_order();
		$url   = $order ? esc_url( (string) $order->get_meta( '_pixgo_qr_image_url', true ) ) : '';

		if ( '' === $url ) {
			return '<div class="pixgo-payments-wc-template-qr pixgo-payments-wc-template-placeholder">' . esc_html__( 'QR Code PixGo', 'pixgo-payments-wc' ) . '</div>';
		}

		return '<div class="pixgo-payments-wc-template-qr"><img src="' . $url . '" alt="' . esc_attr__( 'QR Code PIX', 'pixgo-payments-wc' ) . '"></div>';
	}

	/**
	 * PIX copy-paste shortcode.
	 *
	 * @return string
	 */
	public static function shortcode_pix_code() {
		$order = self::get_current_order();
		$code  = $order ? (string) $order->get_meta( '_pixgo_qr_code', true ) : '000201...PIXGO...PSILOSHOP';

		return '<textarea id="pixgo-payments-wc-code" class="pixgo-payments-wc-template-code" readonly rows="4">' . esc_textarea( $code ) . '</textarea>';
	}

	/**
	 * Copy button shortcode.
	 *
	 * @return string
	 */
	public static function shortcode_copy_button() {
		return '<button type="button" class="button pixgo-payments-wc-button" data-pixgo-copy="#pixgo-payments-wc-code">' . esc_html__( 'Copiar codigo PIX', 'pixgo-payments-wc' ) . '</button>';
	}

	/**
	 * Payment status shortcode.
	 *
	 * @return string
	 */
	public static function shortcode_payment_status() {
		$order = self::get_current_order();
		$text  = $order && $order->is_paid()
			? __( 'Pagamento confirmado. Seu pedido foi atualizado.', 'pixgo-payments-wc' )
			: __( 'Estamos acompanhando a confirmacao automaticamente.', 'pixgo-payments-wc' );

		return '<p class="pixgo-payments-wc-status-text">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Approved message shortcode.
	 *
	 * @return string
	 */
	public static function shortcode_approved_message() {
		$order  = self::get_current_order();
		$hidden = $order && $order->is_paid() ? '' : ' hidden';

		return '<div class="pixgo-payments-wc-approved"' . $hidden . '><p class="pixgo-payments-wc-approved-kicker">' . esc_html__( 'Pagamento confirmado', 'pixgo-payments-wc' ) . '</p><h3>' . esc_html__( 'Pedido aprovado', 'pixgo-payments-wc' ) . '</h3><p>' . esc_html__( 'Seu pagamento foi confirmado e o pedido esta pronto para envio. Pode ficar tranquilo: agora e com a equipe da Psiloshop.', 'pixgo-payments-wc' ) . '</p><p class="pixgo-payments-wc-approved-note">' . esc_html__( 'Assim que o pedido for despachado, voce recebera o codigo de rastreio por email.', 'pixgo-payments-wc' ) . '</p></div>';
	}

	/**
	 * Order number shortcode.
	 *
	 * @return string
	 */
	public static function shortcode_order_number() {
		$order = self::get_current_order();
		return esc_html( $order ? $order->get_order_number() : '0000' );
	}

	/**
	 * Order total shortcode.
	 *
	 * @return string
	 */
	public static function shortcode_order_total() {
		$order = self::get_current_order();
		return wp_kses_post( $order ? $order->get_formatted_order_total() : 'R$ 0,00' );
	}

	/**
	 * Default page content.
	 *
	 * @return string
	 */
	private static function get_default_template_content() {
		return '<!-- wp:group {"className":"pixgo-payments-wc-template-card"} -->
<div class="wp-block-group pixgo-payments-wc-template-card"><!-- wp:heading -->
<h2>Finalize seu pagamento com PIX</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Pedido #[pixgo_order_number] - [pixgo_order_total]</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"pixgo-payments-wc-status-text"} -->
<p class="pixgo-payments-wc-status-text">O QR Code fica disponivel por ate 30 minutos. Assim que o banco confirmar, atualizamos seu pedido automaticamente.</p>
<!-- /wp:paragraph -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:shortcode -->
[pixgo_qr_code]
<!-- /wp:shortcode --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Use o app do seu banco para escanear o QR Code ou copie o codigo PIX abaixo.</p>
<!-- /wp:paragraph -->

<!-- wp:shortcode -->
[pixgo_pix_code]
<!-- /wp:shortcode -->

<!-- wp:shortcode -->
[pixgo_copy_button]
<!-- /wp:shortcode -->

<!-- wp:shortcode -->
[pixgo_payment_status]
<!-- /wp:shortcode --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:shortcode -->
[pixgo_approved_message]
<!-- /wp:shortcode --></div>
<!-- /wp:group -->';
	}
}
