<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Regra central de negócio: quais turnos têm vaga em uma determinada data.
 */
class AE_Disponibilidade {

	/**
	 * Dias de espera para preparação do produto, configurável no admin.
	 * Ex.: 1 = a entrega mais próxima possível é amanhã + 1 dia.
	 */
	public static function dias_preparo() {
		return max( 0, (int) get_option( 'ae_dias_preparo', 1 ) );
	}

	/**
	 * Se os campos de data/turno devem aparecer no checkout do site. Desligar
	 * isso não apaga agendamentos já feitos nem impede o admin de definir a
	 * data/turno manualmente no pedido (ver AE_Checkout_Fields::exibir_no_admin) -
	 * só esconde a coleta automática no front-end.
	 */
	public static function habilitado_no_checkout() {
		return '1' === get_option( 'ae_checkout_habilitado', '1' );
	}

	/**
	 * Status de pedido padrão que contam como "entrega confirmada" para
	 * aparecer no calendário - usado só enquanto a opção nunca foi salva
	 * (instalação nova ou upgrade de uma versão anterior a este recurso).
	 */
	const STATUS_CALENDARIO_PADRAO = array( 'processing', 'completed' );

	/**
	 * Status de pedido (sem o prefixo "wc-") que fazem o pedido entrar no
	 * calendário e contar como vaga ocupada. Configurável em Turnos >
	 * Configurações - por padrão, "Processando" e "Concluído".
	 *
	 * @return string[]
	 */
	public static function status_calendario() {
		$salvos = get_option( 'ae_status_calendario', null );

		if ( ! is_array( $salvos ) || empty( $salvos ) ) {
			return self::STATUS_CALENDARIO_PADRAO;
		}

		return array_values( array_map( 'sanitize_key', $salvos ) );
	}

	/**
	 * Primeira data disponível para entrega, considerando os dias de preparo.
	 *
	 * @return string Data no formato Y-m-d.
	 */
	public static function data_minima() {
		// +1 dia é o mínimo de antecedência padrão; dias_preparo soma dias extras
		// de espera para preparação do produto (ex.: hoje segunda, 1 dia de espera
		// => a data mais próxima disponível é quarta, não terça).
		return wp_date( 'Y-m-d', strtotime( '+' . ( 1 + self::dias_preparo() ) . ' day' ) );
	}

	/**
	 * Quantos dias à frente a busca pela primeira data disponível percorre
	 * antes de desistir (evita loop longo quando não há turno algum configurado).
	 */
	const LIMITE_BUSCA_PRIMEIRA_DATA = 60;

	/**
	 * Primeira data, a partir de `data_minima()`, que tem pelo menos um turno
	 * com vaga — usada para pré-selecionar data e turno no checkout, em vez de
	 * deixar o cliente descobrir por tentativa.
	 *
	 * @return string Data no formato Y-m-d, ou '' se nenhuma data com vaga for
	 *                encontrada dentro do período de busca.
	 */
	public static function primeira_data_disponivel( $metodo_entrega = '' ) {
		$data = self::data_minima();

		for ( $i = 0; $i < self::LIMITE_BUSCA_PRIMEIRA_DATA; $i++ ) {
			if ( ! empty( self::turnos_disponiveis( $data, $metodo_entrega ) ) ) {
				return $data;
			}

			$data = self::somar_dias( $data, 1 );
		}

		return '';
	}

	/**
	 * Datas, dentro de um período a partir de `data_minima()`, que têm pelo
	 * menos um turno com vaga — usado para desabilitar no calendário do
	 * checkout os dias sem disponibilidade (lotados ou bloqueados).
	 *
	 * @return string[] Datas no formato Y-m-d.
	 */
	public static function datas_disponiveis( $metodo_entrega = '', $dias = self::LIMITE_BUSCA_PRIMEIRA_DATA ) {
		$data       = self::data_minima();
		$disponiveis = array();

		for ( $i = 0; $i < $dias; $i++ ) {
			if ( ! empty( self::turnos_disponiveis( $data, $metodo_entrega ) ) ) {
				$disponiveis[] = $data;
			}

			$data = self::somar_dias( $data, 1 );
		}

		return $disponiveis;
	}

	/**
	 * Soma dias de calendário a uma data no formato Y-m-d, sem passar por
	 * conversão de fuso horário (gmdate(), não wp_date()): $data já é uma
	 * data "pura", sem horário associado, e misturar strtotime() (UTC, fuso
	 * padrão do PHP no WordPress) com wp_date() (fuso do site) fazia a data
	 * "empacar" no mesmo dia em fusos negativos como o do Brasil — meia-noite
	 * UTC do dia seguinte, convertida para UTC-3, ainda cai no dia anterior.
	 * Por isso o calendário do checkout só mostrava a primeira data.
	 */
	private static function somar_dias( $data, $dias ) {
		return gmdate( 'Y-m-d', strtotime( $data . ' +' . (int) $dias . ' day' ) );
	}

	/**
	 * Um turno sem métodos vinculados fica disponível para qualquer método de
	 * entrega (compatibilidade com turnos criados antes deste recurso).
	 */
	private static function turno_aceita_metodo( $turno, $metodo_entrega ) {
		if ( empty( $turno->metodos_entrega ) ) {
			return true;
		}

		return in_array( (string) $metodo_entrega, $turno->metodos_entrega, true );
	}

	/**
	 * @param string $metodo_entrega ID da taxa de entrega (method_id:instance_id)
	 *                                escolhida no checkout. Vazio = ignora o filtro.
	 * @return array Lista de objetos turno com propriedade extra `vagas_restantes`.
	 */
	public static function turnos_disponiveis( $data, $metodo_entrega = '' ) {
		if ( empty( $data ) || $data < self::data_minima() || AE_Dias_Bloqueados::esta_bloqueado( $data ) ) {
			return array();
		}

		$turnos_do_dia = AE_CPT_Turno::listar_turnos_do_dia( $data );
		$disponiveis   = array();

		foreach ( $turnos_do_dia as $turno ) {
			if ( ! self::turno_aceita_metodo( $turno, $metodo_entrega ) ) {
				continue;
			}

			// Ajuste manual de limite para essa data+turno (ex.: só 1 vaga na
			// quarta dia 30, mesmo o turno tendo limite normal maior) tem
			// prioridade sobre o limite_por_dia padrão do turno.
			$limite_ajustado    = AE_Limite_Excecao::obter( $data, $turno->id );
			$limite             = null !== $limite_ajustado ? $limite_ajustado : $turno->limite_por_dia;
			$turno->limite_por_dia = $limite;

			$ocupadas = AE_Agendamentos::contar_ativos( $data, $turno->id );
			$restante = $limite - $ocupadas;

			if ( $restante > 0 ) {
				$turno->vagas_restantes = $restante;
				$disponiveis[]          = $turno;
			}
		}

		return $disponiveis;
	}

	public static function turno_tem_vaga( $data, $turno_id, $metodo_entrega = '' ) {
		foreach ( self::turnos_disponiveis( $data, $metodo_entrega ) as $turno ) {
			if ( (int) $turno->id === (int) $turno_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reserva uma vaga de forma atômica usando lock nomeado do MySQL,
	 * evitando overbooking por requisições concorrentes no checkout.
	 *
	 * @return int|WP_Error ID do agendamento criado ou erro se não houver mais vaga.
	 */
	public static function reservar( $order_id, $data, $turno_id, $metodo_entrega = '' ) {
		global $wpdb;

		$lock_key = 'ae_reserva_' . $data . '_' . $turno_id;
		$obtido   = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_key ) );

		if ( '1' !== $obtido ) {
			return new WP_Error( 'ae_lock_falhou', __( 'Não foi possível confirmar a vaga, tente novamente.', 'agendar-entregas' ) );
		}

		try {
			if ( ! self::turno_tem_vaga( $data, $turno_id, $metodo_entrega ) ) {
				return new WP_Error( 'ae_sem_vaga', __( 'Este turno não tem mais vagas disponíveis para a data escolhida.', 'agendar-entregas' ) );
			}

			return AE_Agendamentos::criar( $order_id, $data, $turno_id );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
		}
	}

	/**
	 * Métodos de entrega configurados nas zonas de frete do WooCommerce, para
	 * uso nos formulários do admin (vincular turnos a um método).
	 *
	 * @return array ID da taxa (method_id:instance_id) => rótulo "Zona — Método".
	 */
	public static function metodos_entrega_disponiveis() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}

		$metodos = array();
		$zonas   = WC_Shipping_Zones::get_zones();
		$zonas[] = array(
			'zone_name'       => __( 'Localidades não cobertas por outras zonas', 'agendar-entregas' ),
			'shipping_methods' => ( new WC_Shipping_Zone( 0 ) )->get_shipping_methods(),
		);

		foreach ( $zonas as $zona ) {
			foreach ( $zona['shipping_methods'] as $instance_id => $metodo ) {
				$chave             = $metodo->id . ':' . $instance_id;
				$metodos[ $chave ] = sprintf( '%s — %s', $zona['zone_name'], $metodo->get_title() );
			}
		}

		// "Retirada no local" (Configurações > Frete > Local pickup) é um recurso
		// nativo do WooCommerce independente das zonas de frete: só existe como
		// opção de entrega no checkout se estiver ativado nessa tela. Cada
		// endereço cadastrado vira uma opção própria (mesmo rate id que o
		// WooCommerce usa no checkout: "pickup_location:{índice}"), já que o
		// cliente escolhe entre eles individualmente, não "retirada" genérica.
		if ( self::retirada_local_ativa() ) {
			// Sem locais habilitados, o WooCommerce não oferece "Retirada no
			// local" como opção real no checkout, então não há o que listar.
			foreach ( self::locais_retirada() as $indice => $local ) {
				$metodos[ 'pickup_location:' . $indice ] = self::formatar_local_retirada( $local );
			}
		}

		return $metodos;
	}

	/**
	 * Verifica se "Retirada no local" está ativado em Configurações > Frete.
	 */
	public static function retirada_local_ativa() {
		$configuracao = get_option( 'woocommerce_pickup_location_settings' );

		return ! empty( $configuracao['enabled'] ) && 'yes' === $configuracao['enabled'];
	}

	/**
	 * Endereços de retirada cadastrados em Configurações > Frete > Retirada no
	 * local. O WooCommerce guarda a lista numa option própria (não dentro de
	 * woocommerce_pickup_location_settings) e só cria uma opção de checkout
	 * para os locais marcados como "enabled" (ver
	 * WC_Blocks\Shipping\PickupLocation::calculate_shipping()).
	 */
	public static function locais_retirada() {
		$locais = get_option( 'pickup_location_pickup_locations', array() );

		// Não usar array_values() aqui: o índice de cada local é o mesmo usado
		// pelo WooCommerce no rate id "pickup_location:{índice}" no checkout
		// (calculate_shipping() preserva as chaves originais ao pular os
		// desabilitados), então reindexar quebraria esse casamento quando
		// houver locais desabilitados no meio da lista.
		return array_filter(
			(array) $locais,
			function ( $local ) {
				return ! empty( $local['enabled'] );
			}
		);
	}

	/**
	 * Rótulo "Nome — endereço" de um local de retirada cadastrado.
	 */
	private static function formatar_local_retirada( $local ) {
		$nome = ! empty( $local['name'] ) ? $local['name'] : __( 'Retirada no local', 'agendar-entregas' );

		$endereco = ! empty( $local['address'] ) ? (array) $local['address'] : array();
		$partes   = array_filter(
			array(
				isset( $endereco['address_1'] ) ? $endereco['address_1'] : '',
				isset( $endereco['city'] ) ? $endereco['city'] : '',
				isset( $endereco['state'] ) ? $endereco['state'] : '',
			)
		);

		return $partes ? sprintf( '%s — %s', $nome, implode( ', ', $partes ) ) : $nome;
	}
}
