<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AE_Agendamentos {

	const STATUS_RESERVADO = 'reservado';
	const STATUS_CONFIRMADO = 'confirmado';
	const STATUS_CANCELADO = 'cancelado';

	public static function nome_tabela() {
		global $wpdb;
		return $wpdb->prefix . 'ae_agendamentos';
	}

	public static function criar_tabela() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tabela          = self::nome_tabela();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$tabela} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			data_entrega DATE NOT NULL,
			turno_id BIGINT UNSIGNED NOT NULL,
			turno_nome VARCHAR(191) NOT NULL DEFAULT '',
			turno_hora_inicio VARCHAR(5) NOT NULL DEFAULT '',
			turno_hora_fim VARCHAR(5) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'reservado',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY data_turno (data_entrega, turno_id, status),
			KEY order_id (order_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Nome e horário do turno a guardar junto com o agendamento (ver `criar()`
	 * e `definir_para_pedido()`): assim o calendário continua mostrando a
	 * informação certa mesmo que o turno seja renomeado ou excluído depois -
	 * sem isso, excluir um turno com histórico fazia pedidos antigos
	 * aparecerem como "Turno removido" para sempre, mesmo os que ainda não
	 * foram entregues. O horário também serve para desambiguar turnos órfãos
	 * cujo nome é igual ao de outro turno atual mas o horário é diferente
	 * (ver `turnos_orfaos()` e a sugestão de correspondência na tela de admin).
	 */
	private static function obter_dados_turno( $turno_id ) {
		$turno = AE_CPT_Turno::obter_turno( $turno_id );

		return array(
			'nome'        => $turno ? $turno->nome : '',
			'hora_inicio' => $turno ? $turno->hora_inicio : '',
			'hora_fim'    => $turno ? $turno->hora_fim : '',
		);
	}

	/**
	 * Conta agendamentos ativos (reservado ou confirmado) para uma data + turno.
	 */
	public static function contar_ativos( $data, $turno_id ) {
		global $wpdb;
		$tabela = self::nome_tabela();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tabela} WHERE data_entrega = %s AND turno_id = %d AND status IN (%s, %s)",
				$data,
				$turno_id,
				self::STATUS_RESERVADO,
				self::STATUS_CONFIRMADO
			)
		);
	}

	public static function criar( $order_id, $data, $turno_id ) {
		global $wpdb;
		$tabela = self::nome_tabela();
		$turno  = self::obter_dados_turno( $turno_id );

		$wpdb->insert(
			$tabela,
			array(
				'order_id'          => $order_id,
				'data_entrega'      => $data,
				'turno_id'          => $turno_id,
				'turno_nome'        => $turno['nome'],
				'turno_hora_inicio' => $turno['hora_inicio'],
				'turno_hora_fim'    => $turno['hora_fim'],
				'status'            => self::STATUS_RESERVADO,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	public static function obter_por_pedido( $order_id ) {
		global $wpdb;
		$tabela = self::nome_tabela();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tabela} WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order_id )
		);
	}

	/**
	 * Cria ou substitui o agendamento de um pedido (usado ao agendar um pedido
	 * manual do admin, onde não há checkout envolvido).
	 */
	public static function definir_para_pedido( $order_id, $data, $turno_id, $status ) {
		global $wpdb;
		$tabela = self::nome_tabela();

		$existente = self::obter_por_pedido( $order_id );
		$turno     = self::obter_dados_turno( $turno_id );

		if ( $existente ) {
			return $wpdb->update(
				$tabela,
				array(
					'data_entrega'      => $data,
					'turno_id'          => $turno_id,
					'turno_nome'        => $turno['nome'],
					'turno_hora_inicio' => $turno['hora_inicio'],
					'turno_hora_fim'    => $turno['hora_fim'],
					'status'            => $status,
				),
				array( 'id' => $existente->id ),
				array( '%s', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		$wpdb->insert(
			$tabela,
			array(
				'order_id'          => $order_id,
				'data_entrega'      => $data,
				'turno_id'          => $turno_id,
				'turno_nome'        => $turno['nome'],
				'turno_hora_inicio' => $turno['hora_inicio'],
				'turno_hora_fim'    => $turno['hora_fim'],
				'status'            => $status,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	public static function atualizar_status_por_pedido( $order_id, $status ) {
		global $wpdb;
		$tabela = self::nome_tabela();

		return $wpdb->update(
			$tabela,
			array( 'status' => $status ),
			array( 'order_id' => $order_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Reservas 'reservado' criadas há mais de $horas sem confirmação de pagamento.
	 */
	public static function listar_reservas_expiradas( $horas ) {
		global $wpdb;
		$tabela = self::nome_tabela();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabela} WHERE status = %s AND created_at < (NOW() - INTERVAL %d HOUR)",
				self::STATUS_RESERVADO,
				$horas
			)
		);
	}

	/**
	 * Agendamentos ativos entre duas datas, para exibição no calendário admin.
	 */
	public static function listar_periodo( $data_inicio, $data_fim ) {
		global $wpdb;
		$tabela = self::nome_tabela();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabela} WHERE data_entrega BETWEEN %s AND %s AND status IN (%s, %s) ORDER BY data_entrega ASC",
				$data_inicio,
				$data_fim,
				self::STATUS_RESERVADO,
				self::STATUS_CONFIRMADO
			)
		);
	}

	/**
	 * Agrupa os agendamentos cujo turno_id não corresponde a nenhum turno
	 * existente hoje (ex.: o turno foi excluído e recriado com outro ID,
	 * ou os dados do CPT foram perdidos num deploy/restore) - usado na tela
	 * de correção manual em Turnos > Corrigir turnos órfãos.
	 *
	 * @return array[] Lista de { turno_id, turno_nome, turno_hora_inicio,
	 *                 turno_hora_fim, total } por turno órfão.
	 */
	public static function turnos_orfaos() {
		global $wpdb;
		$tabela = self::nome_tabela();

		return $wpdb->get_results(
			"SELECT a.turno_id, a.turno_nome, a.turno_hora_inicio, a.turno_hora_fim, COUNT(*) AS total
			FROM {$tabela} a
			LEFT JOIN {$wpdb->posts} p ON p.ID = a.turno_id AND p.post_type = 'ae_turno'
			WHERE p.ID IS NULL
			GROUP BY a.turno_id, a.turno_nome, a.turno_hora_inicio, a.turno_hora_fim
			ORDER BY a.turno_nome, a.turno_id"
		);
	}

	/**
	 * Reaponta todos os agendamentos de um turno_id órfão para um turno
	 * existente (e atualiza turno_nome/turno_hora_* para os valores atuais
	 * dele), e também corrige o meta _ae_turno_id de cada pedido afetado -
	 * sem isso, o seletor de turno no painel do pedido (meta box "Agendamento
	 * de Entrega") continuava mostrando o ID antigo/órfão mesmo depois da
	 * correção aparecer certa no calendário, já que lê o meta do pedido, não
	 * a tabela de agendamentos. Não mexe em limite de vagas.
	 *
	 * @return int Quantidade de agendamentos afetados.
	 */
	public static function reatribuir_turno( $turno_id_antigo, $turno_id_novo ) {
		global $wpdb;
		$tabela = self::nome_tabela();

		$turno_novo = AE_CPT_Turno::obter_turno( $turno_id_novo );
		if ( ! $turno_novo ) {
			return 0;
		}

		$order_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT order_id FROM {$tabela} WHERE turno_id = %d", $turno_id_antigo )
		);

		$afetados = $wpdb->update(
			$tabela,
			array(
				'turno_id'          => $turno_id_novo,
				'turno_nome'        => $turno_novo->nome,
				'turno_hora_inicio' => $turno_novo->hora_inicio,
				'turno_hora_fim'    => $turno_novo->hora_fim,
			),
			array( 'turno_id' => $turno_id_antigo ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$order->update_meta_data( '_ae_turno_id', $turno_id_novo );
			$order->save();
		}

		return (int) $afetados;
	}

	public static function listar_por_data_turno( $data, $turno_id ) {
		global $wpdb;
		$tabela = self::nome_tabela();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabela} WHERE data_entrega = %s AND turno_id = %d AND status IN (%s, %s) ORDER BY id ASC",
				$data,
				$turno_id,
				self::STATUS_RESERVADO,
				self::STATUS_CONFIRMADO
			)
		);
	}
}
