<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AE_Checkout_Fields {

	public function __construct() {
		add_action( 'woocommerce_review_order_after_shipping', array( $this, 'exibir_campos' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validar' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'salvar' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'exibir_no_email_e_conta' ) );
	}

	public function exibir_campos() {
		// O toggle "Mostrar campos de agendamento no checkout" (tela Turnos)
		// só esconde a coleta no front-end - o admin continua podendo definir
		// a data/turno manualmente no pedido (ver exibir_no_admin()).
		if ( ! AE_Disponibilidade::habilitado_no_checkout() ) {
			return;
		}

		// Oculto por padrão; o JS exibe somente depois que um método de entrega
		// é selecionado (ver assets/js/checkout.js).
		//
		// Usa <div>, não <tr>/<td>: este hook (woocommerce_review_order_after_
		// shipping) é chamado pelo tema dentro de uma <div> (não uma <table> -
		// ver _step-entrega.html.php e o fragmento AJAX correspondente em
		// extension/woocommerce.php), e o parser HTML do navegador descarta
		// <tr>/<td> fora de uma <table>. Com isso, a classe "ae-linha-
		// agendamento" nunca existia de verdade no DOM - o JS de mostrar/
		// esconder procurava por ela e não encontrava nada, então os campos
		// ficavam sempre visíveis (o style="display:none" ia junto com o <tr>
		// descartado).
		echo '<div class="ae-linha-agendamento" style="display:none;">';

		// Pré-seleciona a primeira data com vaga (em vez de deixar em branco)
		// para o cliente já ver uma data e turno escolhidos, podendo trocar.
		$data_padrao = isset( $_POST['ae_data_entrega'] )
			? sanitize_text_field( wp_unslash( $_POST['ae_data_entrega'] ) )
			: AE_Disponibilidade::primeira_data_disponivel( $this->metodo_entrega_escolhido() );

		// type => 'text' (não 'date'): o input nativo de data não tem como
		// desabilitar visualmente dias específicos (lotados/bloqueados), então
		// o JS troca este campo por um datepicker (Flatpickr) que consulta a
		// disponibilidade e desenha os dias indisponíveis riscados/cinza.
		woocommerce_form_field(
			'ae_data_entrega',
			array(
				'type'              => 'text',
				'label'             => __( 'Data de entrega', 'agendar-entregas' ),
				'required'          => true,
				'class'             => array( 'ae-campo-data' ),
				'custom_attributes' => array(
					'readonly'     => 'readonly',
					'autocomplete' => 'off',
				),
				// O campo é readonly (quem assume a interface é o Flatpickr) e por
				// isso pode parecer um texto fixo; o ícone de calendário (CSS) e
				// esta dica deixam claro que dá para clicar e escolher outra data.
				'description'       => __( 'Toque para escolher outra data disponível.', 'agendar-entregas' ),
				'desc_tip'          => false,
			),
			$data_padrao
		);

		woocommerce_form_field(
			'ae_turno',
			array(
				'type'     => 'select',
				'label'    => __( 'Horário', 'agendar-entregas' ),
				'required' => true,
				'class'    => array( 'ae-campo-turno' ),
				'options'  => array( '' => __( 'Selecione a data primeiro', 'agendar-entregas' ) ),
			),
			isset( $_POST['ae_turno'] ) ? absint( $_POST['ae_turno'] ) : ''
		);

		echo '</div>';
	}

	public function validar() {
		// Com o toggle desligado os campos nem aparecem no checkout - exigir
		// data/turno nesse caso travaria toda compra. O admin ainda pode
		// definir isso manualmente depois, direto no pedido.
		if ( ! AE_Disponibilidade::habilitado_no_checkout() ) {
			return;
		}

		$data     = isset( $_POST['ae_data_entrega'] ) ? sanitize_text_field( wp_unslash( $_POST['ae_data_entrega'] ) ) : '';
		$turno_id = isset( $_POST['ae_turno'] ) ? absint( $_POST['ae_turno'] ) : 0;

		if ( empty( $data ) || empty( $turno_id ) ) {
			wc_add_notice( __( 'Selecione uma data e turno de entrega.', 'agendar-entregas' ), 'error' );
			return;
		}

		if ( $data < AE_Disponibilidade::data_minima() ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: número de dias de espera */
					__( 'São necessários %d dia(s) de espera para preparação. Escolha uma data mais adiante.', 'agendar-entregas' ),
					AE_Disponibilidade::dias_preparo()
				),
				'error'
			);
			return;
		}

		if ( ! AE_Disponibilidade::turno_tem_vaga( $data, $turno_id, $this->metodo_entrega_escolhido() ) ) {
			wc_add_notice( __( 'O turno escolhido não tem mais vagas para esta data. Selecione outra opção.', 'agendar-entregas' ), 'error' );
		}
	}

	/**
	 * ID da taxa (method_id:instance_id) do método de entrega escolhido pelo
	 * cliente na sessão de checkout atual.
	 */
	private function metodo_entrega_escolhido() {
		if ( ! WC()->session ) {
			return '';
		}

		$metodos = WC()->session->get( 'chosen_shipping_methods' );

		return ! empty( $metodos[0] ) ? $metodos[0] : '';
	}

	public function salvar( $order_id ) {
		$data     = isset( $_POST['ae_data_entrega'] ) ? sanitize_text_field( wp_unslash( $_POST['ae_data_entrega'] ) ) : '';
		$turno_id = isset( $_POST['ae_turno'] ) ? absint( $_POST['ae_turno'] ) : 0;

		if ( empty( $data ) || empty( $turno_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Usa a API do WC_Order (não update_post_meta) para funcionar também
		// com o armazenamento de pedidos em tabelas próprias (HPOS).
		$order->update_meta_data( '_ae_data_entrega', $data );
		$order->update_meta_data( '_ae_turno_id', $turno_id );
		$order->save();
	}

	private function texto_agendamento( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		$data     = $order->get_meta( '_ae_data_entrega', true );
		$turno_id = $order->get_meta( '_ae_turno_id', true );

		if ( empty( $data ) || empty( $turno_id ) ) {
			return '';
		}

		$turno = AE_CPT_Turno::obter_turno( $turno_id );
		if ( ! $turno ) {
			return '';
		}

		return sprintf(
			/* translators: 1: data formatada, 2: nome do turno, 3: horário inicial, 4: horário final */
			__( '%1$s — %2$s (%3$s às %4$s)', 'agendar-entregas' ),
			// mysql2date(), não wp_date()+strtotime(): $data é uma data "pura"
			// (AAAA-MM-DD); ver o mesmo comentário em includes/admin/views/dias-bloqueados.php.
			mysql2date( get_option( 'date_format', 'd/m/Y' ), $data ),
			$turno->nome,
			$turno->hora_inicio,
			$turno->hora_fim
		);
	}

	public function exibir_no_email_e_conta( $order ) {
		$texto = $this->texto_agendamento( $order->get_id() );
		if ( empty( $texto ) ) {
			return;
		}

		echo '<p><strong>' . esc_html__( 'Entrega agendada:', 'agendar-entregas' ) . '</strong> ' . esc_html( $texto ) . '</p>';
	}
}
