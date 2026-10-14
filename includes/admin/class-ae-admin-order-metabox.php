<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permite agendar a entrega diretamente na tela de edição do pedido, para
 * pedidos criados manualmente no admin (sem passar pelo checkout).
 */
class AE_Admin_Order_Metabox {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'registrar_metabox' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'salvar' ) );

		// Coluna "Data de entrega" na lista de pedidos (Painel > WooCommerce >
		// Pedidos) - hooks diferentes conforme o pedido está na tela clássica
		// (CPT) ou no armazenamento em tabelas próprias do WooCommerce (HPOS).
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'adicionar_coluna' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'renderizar_coluna_classica' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_sortable_columns', array( $this, 'tornar_coluna_ordenavel' ) );
		add_action( 'pre_get_posts', array( $this, 'ordenar_coluna_classica' ) );

		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'adicionar_coluna' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'renderizar_coluna_hpos' ), 10, 2 );
	}

	/**
	 * Insere a coluna logo depois de "Status" (antes de "Total"), para ficar
	 * perto da informação que o admin mais associa a ela ao escanear a lista.
	 * Se por algum motivo "order_status" não existir (lista customizada por
	 * outro plugin), cai no fallback de colocar a coluna no fim.
	 */
	public function adicionar_coluna( $colunas ) {
		$resultado = array();

		foreach ( $colunas as $chave => $rotulo ) {
			$resultado[ $chave ] = $rotulo;

			if ( 'order_status' === $chave ) {
				$resultado['ae_data_entrega'] = __( 'Data de entrega', 'agendar-entregas' );
			}
		}

		if ( ! isset( $resultado['ae_data_entrega'] ) ) {
			$resultado['ae_data_entrega'] = __( 'Data de entrega', 'agendar-entregas' );
		}

		return $resultado;
	}

	public function tornar_coluna_ordenavel( $colunas ) {
		$colunas['ae_data_entrega'] = 'ae_data_entrega';
		return $colunas;
	}

	/**
	 * Permite ordenar a lista de pedidos pela coluna - só na tela clássica
	 * (CPT); o HPOS tem seu próprio mecanismo de query, mais custoso de
	 * cobrir aqui, e este site roda a tela clássica (confirmado em uso).
	 */
	public function ordenar_coluna_classica( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'ae_data_entrega' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', '_ae_data_entrega' );
		$query->set( 'orderby', 'meta_value' );
	}

	public function renderizar_coluna_classica( $coluna, $post_id ) {
		if ( 'ae_data_entrega' !== $coluna ) {
			return;
		}

		$this->exibir_celula( wc_get_order( $post_id ) );
	}

	public function renderizar_coluna_hpos( $coluna, $order ) {
		if ( 'ae_data_entrega' !== $coluna ) {
			return;
		}

		$this->exibir_celula( $order );
	}

	/**
	 * Data + turno (quando houver) numa célula só, com o horário em cinza
	 * numa segunda linha - dá pra bater o olho na lista inteira de pedidos e
	 * já ver quem entrega quando, sem abrir cada um.
	 */
	private function exibir_celula( $order ) {
		if ( ! $order ) {
			echo '—';
			return;
		}

		$data = $order->get_meta( '_ae_data_entrega', true );
		if ( empty( $data ) ) {
			echo '—';
			return;
		}

		// mysql2date(), não wp_date()+strtotime(): $data é uma data "pura"
		// (AAAA-MM-DD); ver o mesmo comentário em includes/admin/views/dias-bloqueados.php.
		echo esc_html( mysql2date( get_option( 'date_format', 'd/m/Y' ), $data ) );

		$turno_id = (int) $order->get_meta( '_ae_turno_id', true );
		$turno    = $turno_id ? AE_CPT_Turno::obter_turno( $turno_id ) : null;

		if ( $turno ) {
			echo '<br /><span style="color:#777;">' . esc_html( $turno->nome . ' · ' . $turno->hora_inicio . '-' . $turno->hora_fim ) . '</span>';
		}
	}

	/**
	 * Funciona tanto com pedidos como CPT quanto com o armazenamento em
	 * tabelas próprias do WooCommerce (HPOS): a tela de edição usa uma "screen
	 * id" diferente em cada caso.
	 */
	private function tela_pedido() {
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			return wc_get_page_screen_id( 'shop-order' );
		}

		return 'shop_order';
	}

	public function registrar_metabox() {
		add_meta_box(
			'ae_agendamento_pedido',
			__( 'Agendamento de Entrega', 'agendar-entregas' ),
			array( $this, 'render' ),
			$this->tela_pedido(),
			'side',
			'default'
		);
	}

	public function render( $post_ou_pedido ) {
		$order = ( $post_ou_pedido instanceof WP_Post ) ? wc_get_order( $post_ou_pedido->ID ) : wc_get_order( $post_ou_pedido );
		if ( ! $order ) {
			return;
		}

		$data_atual  = $order->get_meta( '_ae_data_entrega', true );
		$turno_atual = (int) $order->get_meta( '_ae_turno_id', true );

		wp_nonce_field( 'ae_salvar_agendamento_pedido', 'ae_agendamento_nonce' );
		?>
		<p>
			<label for="ae_data_entrega_admin"><strong><?php esc_html_e( 'Data de entrega', 'agendar-entregas' ); ?></strong></label><br />
			<input type="date" style="width:100%;" id="ae_data_entrega_admin" name="ae_data_entrega_admin"
				value="<?php echo esc_attr( $data_atual ); ?>" />
		</p>
		<p>
			<label for="ae_turno_admin"><strong><?php esc_html_e( 'Turno', 'agendar-entregas' ); ?></strong></label><br />
			<select id="ae_turno_admin" name="ae_turno_admin" style="width:100%;">
				<option value=""><?php esc_html_e( '— Nenhum —', 'agendar-entregas' ); ?></option>
				<?php foreach ( AE_CPT_Turno::listar_turnos() as $turno ) : ?>
					<option value="<?php echo esc_attr( $turno->id ); ?>" <?php selected( $turno_atual, $turno->id ); ?>>
						<?php echo esc_html( $turno->nome . ' (' . $turno->hora_inicio . ' - ' . $turno->hora_fim . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">
			<?php esc_html_e( 'Agendamento manual: não valida disponibilidade de vagas, útil para pedidos criados diretamente no admin.', 'agendar-entregas' ); ?>
		</p>
		<?php
	}

	public function salvar( $order_id ) {
		if ( ! isset( $_POST['ae_agendamento_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ae_agendamento_nonce'] ) ), 'ae_salvar_agendamento_pedido' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$data     = isset( $_POST['ae_data_entrega_admin'] ) ? sanitize_text_field( wp_unslash( $_POST['ae_data_entrega_admin'] ) ) : '';
		$turno_id = isset( $_POST['ae_turno_admin'] ) ? absint( $_POST['ae_turno_admin'] ) : 0;

		if ( empty( $data ) || empty( $turno_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_ae_data_entrega', $data );
		$order->update_meta_data( '_ae_turno_id', $turno_id );
		$order->save();

		$status = $order->has_status( array( 'processing', 'completed' ) )
			? AE_Agendamentos::STATUS_CONFIRMADO
			: AE_Agendamentos::STATUS_RESERVADO;

		AE_Agendamentos::definir_para_pedido( $order_id, $data, $turno_id, $status );
	}
}
