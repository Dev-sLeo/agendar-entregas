<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AE_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$this->includes();
		$this->atualizar_banco_se_necessario();
		$this->init_modules();
	}

	/**
	 * Cria/atualiza tabelas novas em sites onde o plugin já estava ativo (o
	 * hook de ativação só roda uma vez, ao ativar pela primeira vez).
	 */
	private function atualizar_banco_se_necessario() {
		if ( get_option( 'ae_db_version' ) === AE_VERSION ) {
			return;
		}

		AE_Limite_Excecao::criar_tabela();
		// dbDelta() é idempotente e também aplica ALTER TABLE em colunas novas
		// (ex.: turno_nome) em instalações que já tinham a tabela - necessário
		// sempre que a versão mudar, não só na primeira ativação.
		AE_Agendamentos::criar_tabela();

		update_option( 'ae_db_version', AE_VERSION );
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Agendar Entregas requer o WooCommerce ativo.', 'agendar-entregas' ) . '</p></div>';
	}

	private function includes() {
		require_once AE_PLUGIN_DIR . 'includes/class-ae-logger.php';
		require_once AE_PLUGIN_DIR . 'includes/class-ae-cpt-turno.php';
		require_once AE_PLUGIN_DIR . 'includes/class-ae-dias-bloqueados.php';
		require_once AE_PLUGIN_DIR . 'includes/class-ae-agendamentos.php';
		require_once AE_PLUGIN_DIR . 'includes/class-ae-limite-excecao.php';
		require_once AE_PLUGIN_DIR . 'includes/class-ae-disponibilidade.php';
		require_once AE_PLUGIN_DIR . 'includes/checkout/class-ae-checkout-fields.php';
		require_once AE_PLUGIN_DIR . 'includes/checkout/class-ae-checkout-assets.php';
		require_once AE_PLUGIN_DIR . 'includes/order/class-ae-order-hooks.php';
		require_once AE_PLUGIN_DIR . 'includes/import/class-ae-xlsx-reader.php';
		require_once AE_PLUGIN_DIR . 'includes/import/class-ae-importador-dias-bloqueados.php';
		require_once AE_PLUGIN_DIR . 'includes/import/class-ae-google-sheets-sync.php';
		require_once AE_PLUGIN_DIR . 'includes/admin/class-ae-admin-menu.php';
		require_once AE_PLUGIN_DIR . 'includes/admin/class-ae-admin-turnos.php';
		require_once AE_PLUGIN_DIR . 'includes/admin/class-ae-admin-calendar.php';
		require_once AE_PLUGIN_DIR . 'includes/admin/class-ae-admin-importar.php';
		require_once AE_PLUGIN_DIR . 'includes/admin/class-ae-admin-order-metabox.php';
	}

	private function init_modules() {
		new AE_CPT_Turno();
		new AE_Checkout_Fields();
		new AE_Checkout_Assets();
		new AE_Order_Hooks();
		new AE_Google_Sheets_Sync();

		// Instanciadas sempre (não só quando is_admin()): AE_Admin_Calendar
		// registra a rota REST 'ae/v1/eventos' em 'rest_api_init', e uma
		// requisição para /wp-json/... não é considerada "admin" mesmo
		// partindo de uma página do wp-admin — se essas classes só fossem
		// carregadas com is_admin(), a rota nunca existiria e o WordPress
		// responderia rest_no_route. Os hooks internas delas (admin_menu,
		// admin_post_*, admin_enqueue_scripts) já só disparam no admin mesmo.
		new AE_Admin_Menu();
		new AE_Admin_Turnos();
		new AE_Admin_Calendar();
		new AE_Admin_Importar();
		new AE_Admin_Order_Metabox();
	}

	public static function activate() {
		require_once AE_PLUGIN_DIR . 'includes/class-ae-agendamentos.php';
		require_once AE_PLUGIN_DIR . 'includes/class-ae-dias-bloqueados.php';
		require_once AE_PLUGIN_DIR . 'includes/class-ae-limite-excecao.php';
		require_once AE_PLUGIN_DIR . 'includes/import/class-ae-google-sheets-sync.php';

		AE_Agendamentos::criar_tabela();
		AE_Dias_Bloqueados::criar_tabela();
		AE_Limite_Excecao::criar_tabela();

		if ( ! wp_next_scheduled( 'ae_expirar_reservas' ) ) {
			wp_schedule_event( time(), 'hourly', 'ae_expirar_reservas' );
		}

		if ( ! wp_next_scheduled( AE_Google_Sheets_Sync::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'twicedaily', AE_Google_Sheets_Sync::CRON_HOOK );
		}

		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'ae_expirar_reservas' );
		wp_clear_scheduled_hook( AE_Google_Sheets_Sync::CRON_HOOK );
		flush_rewrite_rules();
	}
}
