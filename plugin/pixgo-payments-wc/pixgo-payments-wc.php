<?php
/**
 * Plugin Name: PixGo Payments WC
 * Plugin URI: https://pixgo.org/
 * Description: PixGo PIX gateway for WooCommerce with QR Code, copy and paste code, and signed webhooks.
 * Version: 1.1.9
 * Author: PixGo Integration
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: pixgo-payments-wc
 * Domain Path: /languages
 *
 * @package Pixgo_Payments_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PIXGO_PAYMENTS_WC_VERSION', '1.1.9' );
define( 'PIXGO_PAYMENTS_WC_FILE', __FILE__ );
define( 'PIXGO_PAYMENTS_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'PIXGO_PAYMENTS_WC_URL', plugin_dir_url( __FILE__ ) );

require_once PIXGO_PAYMENTS_WC_PATH . 'includes/class-psiloshop-github-plugin-updater.php';

new Psiloshop_GitHub_Plugin_Updater(
	PIXGO_PAYMENTS_WC_FILE,
	PIXGO_PAYMENTS_WC_VERSION,
	'https://raw.githubusercontent.com/egalvani/Psiloshop-updates/main/updates/pixgo-payments-wc.json'
);

add_action( 'before_woocommerce_init', 'pixgo_payments_wc_declare_wc_compatibility' );
add_action( 'plugins_loaded', 'pixgo_payments_wc_load', 11 );
add_action( 'admin_menu', 'pixgo_payments_wc_setup_menu' );
add_action( 'admin_menu', 'pixgo_payments_wc_remove_duplicate_menu', 99 );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pixgo_payments_wc_action_links' );

/**
 * Declares compatibility with WooCommerce features when available.
 */
function pixgo_payments_wc_declare_wc_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
	}
}

/**
 * Loads plugin classes after WooCommerce is available.
 */
function pixgo_payments_wc_load() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'pixgo_payments_wc_missing_woocommerce_notice' );
		return;
	}

	$files = array(
		PIXGO_PAYMENTS_WC_PATH . 'includes/class-pixgo-payments-wc-api.php',
		PIXGO_PAYMENTS_WC_PATH . 'includes/class-pixgo-payments-wc-templates.php',
		PIXGO_PAYMENTS_WC_PATH . 'includes/class-pixgo-payments-wc-gateway.php',
		PIXGO_PAYMENTS_WC_PATH . 'includes/class-pixgo-payments-wc-webhook.php',
	);

	foreach ( $files as $file ) {
		if ( ! is_readable( $file ) ) {
			add_action( 'admin_notices', 'pixgo_payments_wc_incomplete_install_notice' );
			return;
		}
	}

	foreach ( $files as $file ) {
		require_once $file;
	}

	Pixgo_Payments_WC_Webhook::init();
	Pixgo_Payments_WC_Templates::init();

	add_filter( 'woocommerce_payment_gateways', 'pixgo_payments_wc_add_gateway' );
	add_action( 'wp_enqueue_scripts', 'pixgo_payments_wc_frontend_assets' );
	add_action( 'admin_enqueue_scripts', 'pixgo_payments_wc_admin_assets' );
	add_action( 'pixgo_payments_wc_check_order_status', 'pixgo_payments_wc_check_order_status' );
	add_action( 'woocommerce_order_details_before_order_table', 'pixgo_payments_wc_maybe_sync_displayed_order', 5 );
	add_action( 'woocommerce_admin_order_data_after_order_details', 'pixgo_payments_wc_maybe_sync_admin_order' );
	add_action( 'wp_ajax_pixgo_payments_wc_poll_order', 'pixgo_payments_wc_ajax_poll_order' );
	add_action( 'wp_ajax_nopriv_pixgo_payments_wc_poll_order', 'pixgo_payments_wc_ajax_poll_order' );
	add_action( 'admin_post_pixgo_payments_wc_create_template', 'pixgo_payments_wc_create_template_action' );
	add_action( 'admin_post_pixgo_payments_wc_save_template_settings', 'pixgo_payments_wc_save_template_settings_action' );
	add_action( 'admin_post_pixgo_payments_wc_save_alert_settings', 'pixgo_payments_wc_save_alert_settings_action' );
}

/**
 * Registers the gateway with WooCommerce.
 *
 * @param array $gateways Gateway classes.
 * @return array
 */
function pixgo_payments_wc_add_gateway( $gateways ) {
	$gateways[] = 'Pixgo_Payments_WC_Gateway';
	return $gateways;
}

/**
 * Adds quick links on the plugins screen.
 *
 * @param array $links Existing links.
 * @return array
 */
function pixgo_payments_wc_action_links( $links ) {
	$setup_url    = admin_url( 'admin.php?page=pixgo-payments-wc' );
	$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=pixgo_payments_wc' );

	array_unshift(
		$links,
		'<a href="' . esc_url( $setup_url ) . '">' . esc_html__( 'Status', 'pixgo-payments-wc' ) . '</a>',
		'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Configurar', 'pixgo-payments-wc' ) . '</a>'
	);

	return $links;
}

/**
 * Adds setup page under the Psiloshop menu.
 */
function pixgo_payments_wc_setup_menu() {
	add_menu_page(
		'PSILOSHOP',
		'PSILOSHOP',
		'manage_woocommerce',
		'psiloshop',
		'pixgo_payments_wc_render_setup_page',
		'dashicons-store',
		56
	);

	add_submenu_page(
		'psiloshop',
		'PixGo Payments WC',
		'PixGo Payments WC',
		'manage_woocommerce',
		'pixgo-payments-wc',
		'pixgo_payments_wc_render_setup_page'
	);
}

/**
 * Removes the duplicate submenu automatically created for the top-level menu.
 */
function pixgo_payments_wc_remove_duplicate_menu() {
	remove_submenu_page( 'psiloshop', 'psiloshop' );
}

/**
 * Renders setup page.
 */
function pixgo_payments_wc_render_setup_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'pixgo-payments-wc' ) );
	}

	$settings       = get_option( 'woocommerce_pixgo_payments_wc_settings', array() );
	$woocommerce_on = class_exists( 'WooCommerce' );
	$gateway_on     = isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
	$has_api_key    = ! empty( $settings['api_key'] );
	$has_secret     = ! empty( $settings['webhook_secret'] );
	$settings_url   = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=pixgo_payments_wc' );
	$tab            = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status';
	$template_id    = absint( get_option( 'pixgo_payments_wc_template_page_id', 0 ) );
	$template       = $template_id ? get_post( $template_id ) : null;

	echo '<div class="wrap pixgo-payments-wc-setup">';
	echo '<h1>PixGo Payments WC</h1>';
	echo '<nav class="nav-tab-wrapper">';
	echo '<a class="nav-tab ' . esc_attr( 'status' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=pixgo-payments-wc&tab=status' ) ) . '">' . esc_html__( 'Status', 'pixgo-payments-wc' ) . '</a>';
	echo '<a class="nav-tab ' . esc_attr( 'telas' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=pixgo-payments-wc&tab=telas' ) ) . '">' . esc_html__( 'Telas', 'pixgo-payments-wc' ) . '</a>';
	echo '<a class="nav-tab ' . esc_attr( 'alerta' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=pixgo-payments-wc&tab=alerta' ) ) . '">' . esc_html__( 'Alerta PIX', 'pixgo-payments-wc' ) . '</a>';
	echo '</nav>';

	if ( 'alerta' === $tab ) {
		$alert = pixgo_payments_wc_get_alert_settings();

		echo '<p>' . esc_html__( 'Edite o aviso exibido em modal quando o QR Code PIX aparece para o cliente.', 'pixgo-payments-wc' ) . '</p>';
		echo '<div class="pixgo-payments-wc-panel">';
		echo '<h2>' . esc_html__( 'Modal de orientacao sobre alerta bancario', 'pixgo-payments-wc' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pixgo_payments_wc_save_alert_settings' );
		echo '<input type="hidden" name="action" value="pixgo_payments_wc_save_alert_settings">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Exibir modal', 'pixgo-payments-wc' ) . '</th><td><label><input type="checkbox" name="enabled" value="yes" ' . checked( $alert['enabled'], 'yes', false ) . '> ' . esc_html__( 'Mostrar aviso antes do cliente pagar o PIX', 'pixgo-payments-wc' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="pixgo-alert-title">' . esc_html__( 'Titulo', 'pixgo-payments-wc' ) . '</label></th><td><input id="pixgo-alert-title" class="regular-text" type="text" name="title" value="' . esc_attr( $alert['title'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="pixgo-alert-message">' . esc_html__( 'Mensagem', 'pixgo-payments-wc' ) . '</label></th><td><textarea id="pixgo-alert-message" class="large-text" rows="7" name="message">' . esc_textarea( $alert['message'] ) . '</textarea><p class="description">' . esc_html__( 'Use uma mensagem clara para tranquilizar o cliente caso o banco mostre alerta preventivo.', 'pixgo-payments-wc' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="pixgo-alert-button">' . esc_html__( 'Texto do botao', 'pixgo-payments-wc' ) . '</label></th><td><input id="pixgo-alert-button" class="regular-text" type="text" name="button" value="' . esc_attr( $alert['button'] ) . '"></td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Salvar alerta', 'pixgo-payments-wc' ) . '</button></p>';
		echo '</form>';
		echo '<div class="pixgo-payments-wc-alert-preview">';
		echo '<p class="pixgo-payments-wc-preview-label">' . esc_html__( 'Previa', 'pixgo-payments-wc' ) . '</p>';
		echo '<h3>' . esc_html( $alert['title'] ) . '</h3>';
		echo '<p>' . nl2br( esc_html( $alert['message'] ) ) . '</p>';
		echo '<span class="button button-primary">' . esc_html( $alert['button'] ) . '</span>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		return;
	}

	if ( 'telas' === $tab ) {
		echo '<p>' . esc_html__( 'Crie uma pagina padrao com shortcodes PixGo e edite o visual pelo Elementor. O plugin continua exibindo os dados reais do pedido dentro da pagina de obrigado do WooCommerce.', 'pixgo-payments-wc' ) . '</p>';
		echo '<div class="pixgo-payments-wc-panel">';
		echo '<h2>' . esc_html__( 'Template editavel da pagina PIX', 'pixgo-payments-wc' ) . '</h2>';

		if ( $template ) {
			echo '<p>' . esc_html__( 'Template atual:', 'pixgo-payments-wc' ) . ' <strong>' . esc_html( get_the_title( $template ) ) . '</strong></p>';
			echo '<p>';
			if ( class_exists( '\Elementor\Plugin' ) ) {
				echo '<a class="button button-primary" href="' . esc_url( admin_url( 'post.php?post=' . $template_id . '&action=elementor' ) ) . '">' . esc_html__( 'Editar com Elementor', 'pixgo-payments-wc' ) . '</a> ';
			}
			echo '<a class="button" href="' . esc_url( get_edit_post_link( $template_id, '' ) ) . '">' . esc_html__( 'Editar no WordPress', 'pixgo-payments-wc' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( get_permalink( $template_id ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ver pagina', 'pixgo-payments-wc' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'Nenhuma tela editavel foi criada ainda.', 'pixgo-payments-wc' ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pixgo_payments_wc_create_template' );
		echo '<input type="hidden" name="action" value="pixgo_payments_wc_create_template">';
		echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Criar ou recriar tela padrao', 'pixgo-payments-wc' ) . '</button></p>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pixgo_payments_wc_save_template_settings' );
		echo '<input type="hidden" name="action" value="pixgo_payments_wc_save_template_settings">';
		echo '<label for="pixgo-template-page-id"><strong>' . esc_html__( 'Usar outra pagina existente', 'pixgo-payments-wc' ) . '</strong></label>';
		wp_dropdown_pages(
			array(
				'name'              => 'template_page_id',
				'id'                => 'pixgo-template-page-id',
				'selected'          => $template_id,
				'show_option_none'  => __( 'Usar layout nativo do plugin', 'pixgo-payments-wc' ),
				'option_none_value' => 0,
			)
		);
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Salvar tela', 'pixgo-payments-wc' ) . '</button></p>';
		echo '</form>';

		echo '<h3>' . esc_html__( 'Shortcodes disponiveis', 'pixgo-payments-wc' ) . '</h3>';
		echo '<code>[pixgo_qr_code]</code> <code>[pixgo_pix_code]</code> <code>[pixgo_copy_button]</code> <code>[pixgo_payment_status]</code> <code>[pixgo_approved_message]</code> <code>[pixgo_order_number]</code> <code>[pixgo_order_total]</code>';
		echo '</div>';
		echo '</div>';
		return;
	}

	echo '<p>' . esc_html__( 'The plugin can stay active without replacing your current gateway. PixGo appears at checkout only after you enable it in WooCommerce payments.', 'pixgo-payments-wc' ) . '</p>';
	echo '<div class="pixgo-payments-wc-panel">';
	echo '<h2>Status</h2>';
	echo '<table class="widefat striped"><tbody>';
	echo '<tr><td>' . esc_html__( 'WooCommerce active', 'pixgo-payments-wc' ) . '</td><td><strong>' . esc_html( $woocommerce_on ? __( 'Yes', 'pixgo-payments-wc' ) : __( 'No', 'pixgo-payments-wc' ) ) . '</strong></td></tr>';
	echo '<tr><td>' . esc_html__( 'PixGo at checkout', 'pixgo-payments-wc' ) . '</td><td><strong>' . esc_html( $gateway_on ? __( 'Enabled', 'pixgo-payments-wc' ) : __( 'Disabled', 'pixgo-payments-wc' ) ) . '</strong></td></tr>';
	echo '<tr><td>' . esc_html__( 'API Key', 'pixgo-payments-wc' ) . '</td><td><strong>' . esc_html( $has_api_key ? __( 'Saved', 'pixgo-payments-wc' ) : __( 'Missing', 'pixgo-payments-wc' ) ) . '</strong></td></tr>';
	echo '<tr><td>' . esc_html__( 'Webhook Secret', 'pixgo-payments-wc' ) . '</td><td><strong>' . esc_html( $has_secret ? __( 'Saved', 'pixgo-payments-wc' ) : __( 'Missing', 'pixgo-payments-wc' ) ) . '</strong></td></tr>';
	echo '</tbody></table>';
	echo '<p><a class="button button-primary" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open PixGo settings', 'pixgo-payments-wc' ) . '</a></p>';
	echo '</div>';
	echo '</div>';
}

/**
 * Default/editable PixGo fraud-warning modal settings.
 *
 * @return array
 */
function pixgo_payments_wc_get_alert_settings() {
	$defaults = array(
		'enabled' => 'yes',
		'title'   => __( 'Aviso importante antes do PIX', 'pixgo-payments-wc' ),
		'message' => __( 'Alguns bancos digitais podem exibir um alerta preventivo de seguranca ao pagar este QR Code. Esse aviso e um falso positivo e pode acontecer com transacoes PIX de lojas virtuais. Voce esta comprando com a Psiloshop, em um checkout protegido, e pode concluir o pagamento com tranquilidade.', 'pixgo-payments-wc' ),
		'button'  => __( 'Entendi, vou pagar com PIX', 'pixgo-payments-wc' ),
	);
	$saved    = get_option( 'pixgo_payments_wc_alert_settings', array() );

	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	return wp_parse_args( $saved, $defaults );
}

/**
 * Renders the PixGo customer alert modal.
 */
function pixgo_payments_wc_render_alert_modal() {
	$alert = pixgo_payments_wc_get_alert_settings();

	if ( 'yes' !== $alert['enabled'] ) {
		return;
	}

	echo '<div class="pixgo-payments-wc-modal" data-pixgo-fraud-alert hidden>';
	echo '<div class="pixgo-payments-wc-modal-backdrop" data-pixgo-alert-close></div>';
	echo '<div class="pixgo-payments-wc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pixgo-payments-wc-alert-title" tabindex="-1">';
	echo '<p class="pixgo-payments-wc-modal-kicker">' . esc_html__( 'Orientacao de seguranca', 'pixgo-payments-wc' ) . '</p>';
	echo '<h2 id="pixgo-payments-wc-alert-title">' . esc_html( $alert['title'] ) . '</h2>';
	echo '<p>' . nl2br( esc_html( $alert['message'] ) ) . '</p>';
	echo '<button type="button" class="button pixgo-payments-wc-button pixgo-payments-wc-modal-button" data-pixgo-alert-close>' . esc_html( $alert['button'] ) . '</button>';
	echo '</div>';
	echo '</div>';
}

/**
 * Saves editable PixGo alert settings.
 */
function pixgo_payments_wc_save_alert_settings_action() {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'pixgo_payments_wc_save_alert_settings' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'pixgo-payments-wc' ) );
	}

	$settings = array(
		'enabled' => isset( $_POST['enabled'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) ? 'yes' : 'no',
		'title'   => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
		'message' => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '',
		'button'  => isset( $_POST['button'] ) ? sanitize_text_field( wp_unslash( $_POST['button'] ) ) : '',
	);
	$defaults = pixgo_payments_wc_get_alert_settings();

	foreach ( array( 'title', 'message', 'button' ) as $key ) {
		if ( '' === trim( $settings[ $key ] ) ) {
			$settings[ $key ] = $defaults[ $key ];
		}
	}

	update_option( 'pixgo_payments_wc_alert_settings', $settings );
	wp_safe_redirect( admin_url( 'admin.php?page=pixgo-payments-wc&tab=alerta&saved=1' ) );
	exit;
}

/**
 * Creates a default Elementor-editable PixGo page.
 */
function pixgo_payments_wc_create_template_action() {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'pixgo_payments_wc_create_template' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'pixgo-payments-wc' ) );
	}

	$page_id = Pixgo_Payments_WC_Templates::create_default_page();
	update_option( 'pixgo_payments_wc_template_page_id', absint( $page_id ) );

	wp_safe_redirect( admin_url( 'admin.php?page=pixgo-payments-wc&tab=telas&created=1' ) );
	exit;
}

/**
 * Saves template settings.
 */
function pixgo_payments_wc_save_template_settings_action() {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'pixgo_payments_wc_save_template_settings' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'pixgo-payments-wc' ) );
	}

	$template_page_id = isset( $_POST['template_page_id'] ) ? absint( wp_unslash( $_POST['template_page_id'] ) ) : 0;
	update_option( 'pixgo_payments_wc_template_page_id', $template_page_id );

	wp_safe_redirect( admin_url( 'admin.php?page=pixgo-payments-wc&tab=telas&saved=1' ) );
	exit;
}

/**
 * Shows dependency notice.
 */
function pixgo_payments_wc_missing_woocommerce_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'PixGo Payments WC requires WooCommerce to be active.', 'pixgo-payments-wc' ) . '</p></div>';
	}
}

/**
 * Shows incomplete install notice.
 */
function pixgo_payments_wc_incomplete_install_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'PixGo Payments WC is missing required files. Upload the complete ZIP package again.', 'pixgo-payments-wc' ) . '</p></div>';
	}
}

/**
 * Loads checkout/thank-you page assets.
 */
function pixgo_payments_wc_frontend_assets() {
	$template_id      = absint( get_option( 'pixgo_payments_wc_template_page_id', 0 ) );
	$is_template_page = $template_id && function_exists( 'is_page' ) && is_page( $template_id );

	if ( function_exists( 'is_order_received_page' ) && ( is_order_received_page() || is_view_order_page() || $is_template_page ) ) {
		wp_enqueue_style( 'pixgo-payments-wc', PIXGO_PAYMENTS_WC_URL . 'assets/css/frontend.css', array(), PIXGO_PAYMENTS_WC_VERSION );
		wp_enqueue_script( 'pixgo-payments-wc', PIXGO_PAYMENTS_WC_URL . 'assets/js/frontend.js', array(), PIXGO_PAYMENTS_WC_VERSION, true );
		wp_localize_script(
			'pixgo-payments-wc',
			'PixgoPaymentsWC',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'approvedTitle'  => __( 'Pedido aprovado', 'pixgo-payments-wc' ),
				'approvedText'   => __( 'Seu pagamento foi confirmado e o pedido esta pronto para envio. Pode ficar tranquilo: agora e com a equipe da Psiloshop.', 'pixgo-payments-wc' ),
				'trackingNotice' => __( 'Assim que o pedido for despachado, voce recebera o codigo de rastreio por email.', 'pixgo-payments-wc' ),
			)
		);
	}
}

/**
 * Loads admin assets.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function pixgo_payments_wc_admin_assets( $hook_suffix ) {
	if ( 'psiloshop_page_pixgo-payments-wc' === $hook_suffix || 'toplevel_page_psiloshop' === $hook_suffix || 'settings_page_pixgo-payments-wc' === $hook_suffix || 'woocommerce_page_wc-settings' === $hook_suffix ) {
		wp_enqueue_style( 'pixgo-payments-wc-admin', PIXGO_PAYMENTS_WC_URL . 'assets/css/admin.css', array(), PIXGO_PAYMENTS_WC_VERSION );
	}
}

/**
 * Checks PixGo status as a fallback when webhook delivery is delayed or blocked.
 *
 * @param int $order_id Order ID.
 */
function pixgo_payments_wc_check_order_status( $order_id ) {
	$order = wc_get_order( absint( $order_id ) );

	if ( ! $order || 'pixgo_payments_wc' !== $order->get_payment_method() || $order->is_paid() ) {
		return;
	}

	$gateways = WC()->payment_gateways()->payment_gateways();

	if ( empty( $gateways['pixgo_payments_wc'] ) || ! $gateways['pixgo_payments_wc'] instanceof Pixgo_Payments_WC_Gateway ) {
		return;
	}

	$gateways['pixgo_payments_wc']->sync_order_status_from_pixgo( $order, true );
}

/**
 * Syncs PixGo status when the customer opens the order page.
 *
 * @param WC_Order $order Order.
 */
function pixgo_payments_wc_maybe_sync_displayed_order( $order ) {
	if ( $order instanceof WC_Order && 'pixgo_payments_wc' === $order->get_payment_method() && ! $order->is_paid() ) {
		pixgo_payments_wc_check_order_status( $order->get_id() );
	}
}

/**
 * Syncs PixGo status when the merchant opens the order in admin.
 *
 * @param WC_Order $order Order.
 */
function pixgo_payments_wc_maybe_sync_admin_order( $order ) {
	if ( $order instanceof WC_Order && 'pixgo_payments_wc' === $order->get_payment_method() && ! $order->is_paid() ) {
		pixgo_payments_wc_check_order_status( $order->get_id() );
	}
}

/**
 * Polls PixGo status from the thank-you page for a near real-time experience.
 */
function pixgo_payments_wc_ajax_poll_order() {
	$order_id  = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
	$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
	$nonce     = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

	if ( ! $order_id || ! wp_verify_nonce( $nonce, 'pixgo_payments_wc_poll_' . $order_id ) ) {
		wp_send_json_error( array( 'message' => 'invalid_request' ), 403 );
	}

	$order = wc_get_order( $order_id );

	if ( ! $order || 'pixgo_payments_wc' !== $order->get_payment_method() || ! hash_equals( $order->get_order_key(), $order_key ) ) {
		wp_send_json_error( array( 'message' => 'order_not_found' ), 404 );
	}

	if ( ! $order->is_paid() ) {
		pixgo_payments_wc_check_order_status( $order_id );
		$order = wc_get_order( $order_id );
	}

	wp_send_json_success(
		array(
			'paid'   => $order ? $order->is_paid() : false,
			'status' => $order ? $order->get_status() : '',
		)
	);
}
