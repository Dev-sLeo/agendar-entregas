<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AE_Order_Hooks {

	/**
	 * Reservas 'reservado' sem pagamento confirmado expiram após este período.
	 */
	const HORAS_EXPIRACAO = 24;

	public function __construct() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'reservar_vaga' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'atualizar_status_agendamento' ), 10, 4 );
		add_action( 'ae_expirar_reservas', array( $this, 'expirar_reservas' ) );
		add_action( 'admin_post_ae_sincronizar_pedidos', array( $this, 'sincronizar_pedidos_existentes' ) );
	}

	public function reservar_vaga( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$data     = $order->get_meta( '_ae_data_entrega', true );
		$turno_id = $order->get_meta( '_ae_turno_id', true );

		if ( empty( $data ) || empty( $turno_id ) ) {
			return;
		}

		if ( AE_Agendamentos::obter_por_pedido( $order_id ) ) {
			return;
		}

		$resultado = AE_Disponibilidade::reservar( $order_id, $data, $turno_id, $this->metodo_entrega_do_pedido( $order_id ) );

		if ( is_wp_error( $resultado ) ) {
			AE_Logger::erro(
				sprintf(
					'Falha ao reservar vaga do pedido #%d (data: %s, turno: %d): %s',
					$order_id,
					$data,
					$turno_id,
					$resultado->get_error_message()
				)
			);
			throw new Exception( esc_html( $resultado->get_error_message() ) );
		}
	}

	/**
	 * ID da taxa (method_id:instance_id) do método de entrega usado no pedido.
	 */
	private function metodo_entrega_do_pedido( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		foreach ( $order->get_shipping_methods() as $item ) {
			return $item->get_method_id() . ':' . $item->get_instance_id();
		}

		return '';
	}

	public function atualizar_status_agendamento( $order_id, $status_de, $status_para, $order ) {
		// Se o agendamento não foi criado no checkout (ex.: falha pontual no
		// hook de reserva), recria aqui a partir dos meta do pedido, para que
		// a mudança de status não fique "perdida" sem nunca aparecer na agenda.
		$agendamento = $this->garantir_agendamento( $order_id );
		if ( ! $agendamento ) {
			return;
		}

		if ( in_array( $status_para, AE_Disponibilidade::status_calendario(), true ) ) {
			AE_Agendamentos::atualizar_status_por_pedido( $order_id, AE_Agendamentos::STATUS_CONFIRMADO );
		} elseif ( in_array( $status_para, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			AE_Agendamentos::atualizar_status_por_pedido( $order_id, AE_Agendamentos::STATUS_CANCELADO );
		}
	}

	/**
	 * Garante que existe uma linha de agendamento para o pedido, recriando-a a
	 * partir dos meta _ae_data_entrega/_ae_turno_id quando estiver ausente.
	 * O mínimo para existir um agendamento (e aparecer no calendário) é ter a
	 * data - turno_id vazio vira 0 ("Sem turno" no calendário), não bloqueia.
	 */
	private function garantir_agendamento( $order_id ) {
		$agendamento = AE_Agendamentos::obter_por_pedido( $order_id );
		if ( $agendamento ) {
			return $agendamento;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		$data     = $order->get_meta( '_ae_data_entrega', true );
		$turno_id = (int) $order->get_meta( '_ae_turno_id', true );

		if ( empty( $data ) ) {
			return null;
		}

		AE_Agendamentos::criar( $order_id, $data, $turno_id );

		return AE_Agendamentos::obter_por_pedido( $order_id );
	}

	/**
	 * Sincronização manual: recria agendamentos ausentes para pedidos já em
	 * processando/concluído (ex.: pagamento na entrega) que, por algum motivo,
	 * nunca ganharam uma linha em wp_ae_agendamentos no checkout.
	 */
	public function sincronizar_pedidos_existentes() {
		check_admin_referer( 'ae_sincronizar_pedidos' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'agendar-entregas' ) );
		}

		// Usa 'meta_query' (não o atalho 'meta_key'/'meta_compare' do WP_Query):
		// com o armazenamento de pedidos em tabelas próprias do WooCommerce
		// (HPOS) o atalho é ignorado e a busca por meta precisa desse formato.
		$pedidos = wc_get_orders(
			array(
				'limit'      => -1,
				'status'     => AE_Disponibilidade::status_calendario(),
				'meta_query' => array(
					array(
						'key'     => '_ae_data_entrega',
						'compare' => 'EXISTS',
					),
				),
				'return'     => 'ids',
			)
		);

		$sincronizados = 0;
		foreach ( $pedidos as $order_id ) {
			if ( AE_Agendamentos::obter_por_pedido( $order_id ) ) {
				continue;
			}

			if ( $this->garantir_agendamento( $order_id ) ) {
				AE_Agendamentos::atualizar_status_por_pedido( $order_id, AE_Agendamentos::STATUS_CONFIRMADO );
				$sincronizados++;
			}
		}

		AE_Logger::info( sprintf( 'Sincronização manual de pedidos antigos: %d agendamento(s) recriado(s).', $sincronizados ) );

		wp_safe_redirect( admin_url( 'admin.php?page=ae-calendario&ae_sincronizados=' . (int) $sincronizados ) );
		exit;
	}

	/**
	 * Libera vagas de pedidos pendentes há mais de HORAS_EXPIRACAO sem pagamento.
	 */
	public function expirar_reservas() {
		$expiradas = AE_Agendamentos::listar_reservas_expiradas( self::HORAS_EXPIRACAO );

		if ( empty( $expiradas ) ) {
			return;
		}

		foreach ( $expiradas as $reserva ) {
			$order = wc_get_order( $reserva->order_id );

			if ( $order && $order->has_status( AE_Disponibilidade::status_calendario() ) ) {
				AE_Agendamentos::atualizar_status_por_pedido( $reserva->order_id, AE_Agendamentos::STATUS_CONFIRMADO );
				continue;
			}

			AE_Agendamentos::atualizar_status_por_pedido( $reserva->order_id, AE_Agendamentos::STATUS_CANCELADO );
			AE_Logger::info(
				sprintf(
					'Reserva do pedido #%d expirada após %d horas sem pagamento (data: %s, turno: %d) - vaga liberada.',
					$reserva->order_id,
					self::HORAS_EXPIRACAO,
					$reserva->data_entrega,
					$reserva->turno_id
				)
			);
		}
	}
}
