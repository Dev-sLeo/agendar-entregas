<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AE_Admin_Calendar {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'rest_api_init', array( $this, 'registrar_rotas' ) );
	}

	public function enqueue( $hook ) {
		if ( 'entregas_page_ae-calendario' !== $hook && 'toplevel_page_ae-calendario' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ae-fullcalendar',
			'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.11/index.global.min.css',
			array(),
			'6.1.11'
		);

		wp_enqueue_script(
			'ae-fullcalendar',
			'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.11/index.global.min.js',
			array(),
			'6.1.11',
			true
		);

		wp_enqueue_script(
			'ae-fullcalendar-locales',
			'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.11/locales-all.global.min.js',
			array( 'ae-fullcalendar' ),
			'6.1.11',
			true
		);

		wp_enqueue_script(
			'ae-admin-calendar',
			AE_PLUGIN_URL . 'assets/js/admin-calendar.js',
			array( 'ae-fullcalendar', 'ae-fullcalendar-locales' ),
			AE_VERSION,
			true
		);

		wp_localize_script(
			'ae-admin-calendar',
			'aeCalendar',
			array(
				'restUrl'          => esc_url_raw( rest_url( 'ae/v1/eventos' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'orderUrlBase'     => admin_url( 'post.php?action=edit&post=' ),
				'ultimaAtualizacao' => get_option( 'ae_calendario_ultima_atualizacao', '' ),
				'textos'           => array(
					'sucesso'       => __( 'Calendário atualizado com sucesso.', 'agendar-entregas' ),
					'erro'          => __( 'Não foi possível atualizar o calendário. Tente novamente.', 'agendar-entregas' ),
					'nunca'         => __( 'Ainda não sincronizado.', 'agendar-entregas' ),
					'ultimaLabel'   => __( 'Última sincronização', 'agendar-entregas' ),
					'hoje'          => __( 'Hoje', 'agendar-entregas' ),
					'visaoMes'      => __( 'Mês', 'agendar-entregas' ),
					'visaoLista'    => __( 'Lista', 'agendar-entregas' ),
				),
			)
		);
	}

	public function registrar_rotas() {
		register_rest_route(
			'ae/v1',
			'/eventos',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'obter_eventos' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	public function obter_eventos( WP_REST_Request $request ) {
		$inicio = sanitize_text_field( $request->get_param( 'start' ) );
		$fim    = sanitize_text_field( $request->get_param( 'end' ) );

		// Datas do FullCalendar vêm como "Y-m-d" (com ou sem hora/offset); pegar
		// só os 10 primeiros caracteres evita que strtotime()+gmdate() desloque
		// o dia por causa de fuso horário.
		$inicio = $inicio ? substr( $inicio, 0, 10 ) : current_time( 'Y-m-d' );
		$fim    = $fim ? substr( $fim, 0, 10 ) : gmdate( 'Y-m-d', strtotime( '+1 month', current_time( 'timestamp' ) ) );

		// Considera todos os agendamentos ativos (reservado + confirmado) e
		// checa o status real do pedido, em vez de confiar apenas na cópia do
		// status guardada em wp_ae_agendamentos: assim o calendário nunca fica
		// desatualizado se essa cópia não tiver sido sincronizada corretamente.
		$agendamentos = AE_Agendamentos::listar_periodo( $inicio, $fim );

		$agrupado = array();
		foreach ( $agendamentos as $agendamento ) {
			$order = wc_get_order( $agendamento->order_id );
			if ( ! $order || ! $order->has_status( AE_Disponibilidade::status_calendario() ) ) {
				continue;
			}

			$chave = $agendamento->data_entrega . '_' . $agendamento->turno_id;
			if ( ! isset( $agrupado[ $chave ] ) ) {
				$turno           = $agendamento->turno_id ? AE_CPT_Turno::obter_turno( $agendamento->turno_id ) : null;
				$limite_ajustado = AE_Limite_Excecao::obter( $agendamento->data_entrega, $agendamento->turno_id );

				// Agendamento manual sem turno escolhido (mínimo pra aparecer no
				// calendário é ter a data - ver AE_Admin_Order_Metabox::salvar())
				// mostra "Sem turno", distinto de "Turno removido" (que tinha
				// turno, mas foi excluído depois). Se o turno ainda existe, usa o
				// nome atual (reflete renomeações); se foi excluído, cai no nome
				// salvo no momento do agendamento (ver
				// AE_Agendamentos::obter_nome_turno()) em vez de um genérico
				// "Turno removido" - só usa esse texto se nem isso existir
				// (agendamentos muito antigos, de antes desse campo existir).
				if ( empty( $agendamento->turno_id ) ) {
					$nome_turno = __( 'Sem turno', 'agendar-entregas' );
				} elseif ( $turno ) {
					$nome_turno = $turno->nome;
				} elseif ( ! empty( $agendamento->turno_nome ) ) {
					$nome_turno = $agendamento->turno_nome;
				} else {
					$nome_turno = __( 'Turno removido', 'agendar-entregas' );
				}

				$agrupado[ $chave ] = array(
					'data'     => $agendamento->data_entrega,
					'turno'    => $nome_turno,
					'limite'   => null !== $limite_ajustado ? $limite_ajustado : ( $turno ? $turno->limite_por_dia : 0 ),
					'pedidos'  => array(),
				);
			}

			$agrupado[ $chave ]['pedidos'][] = array(
				'order_id' => (int) $agendamento->order_id,
				'status'   => $order->get_status(),
			);
		}

		$eventos = array();
		foreach ( $agrupado as $grupo ) {
			$ocupacao = count( $grupo['pedidos'] );
			$cor      = '#46b450';
			if ( $grupo['limite'] > 0 && $ocupacao >= $grupo['limite'] ) {
				$cor = '#dc3232';
			} elseif ( $grupo['limite'] > 0 && $ocupacao >= ( $grupo['limite'] * 0.7 ) ) {
				$cor = '#ffb900';
			}

			// Sem limite definido (ex.: agendamento manual sem turno), mostra só
			// a contagem - "(1/0)" passaria a falsa impressão de que a vaga já
			// estouraria um limite que nem existe.
			$titulo = $grupo['limite'] > 0
				? sprintf( '%s (%d/%d)', $grupo['turno'], $ocupacao, $grupo['limite'] )
				: sprintf( '%s (%d)', $grupo['turno'], $ocupacao );

			$eventos[] = array(
				'title'    => $titulo,
				'start'    => $grupo['data'],
				'color'    => $cor,
				'extendedProps' => array(
					'pedidos' => $grupo['pedidos'],
					'turno'   => $grupo['turno'],
				),
			);
		}

		update_option( 'ae_calendario_ultima_atualizacao', current_time( 'mysql' ) );

		return rest_ensure_response( $eventos );
	}
}
