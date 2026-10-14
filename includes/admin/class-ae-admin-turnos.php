<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD de turnos (baseado no CPT interno ae_turno) e CRUD de dias bloqueados.
 * Usa formulários próprios em vez da tela nativa de edição de post, para manter
 * a UI focada nos campos relevantes (horário, limite, dias da semana).
 */
class AE_Admin_Turnos {

	public function __construct() {
		add_action( 'admin_post_ae_salvar_turno', array( $this, 'salvar_turno' ) );
		add_action( 'admin_post_ae_excluir_turno', array( $this, 'excluir_turno' ) );
		add_action( 'admin_post_ae_salvar_dia_bloqueado', array( $this, 'salvar_dia_bloqueado' ) );
		add_action( 'admin_post_ae_excluir_dia_bloqueado', array( $this, 'excluir_dia_bloqueado' ) );
		add_action( 'admin_post_ae_salvar_configuracoes', array( $this, 'salvar_configuracoes' ) );
		add_action( 'admin_post_ae_salvar_grupo_metodo', array( $this, 'salvar_grupo_metodo' ) );
		add_action( 'admin_post_ae_salvar_dias_semana_bloqueados', array( $this, 'salvar_dias_semana_bloqueados' ) );
		add_action( 'admin_post_ae_salvar_limite_excecao', array( $this, 'salvar_limite_excecao' ) );
		add_action( 'admin_post_ae_excluir_limite_excecao', array( $this, 'excluir_limite_excecao' ) );
	}

	/**
	 * Ajusta manualmente o limite de vagas de um turno numa data específica
	 * (ex.: só 1 entrega disponível na quarta dia 30, mesmo o turno permitindo
	 * mais normalmente).
	 */
	public function salvar_limite_excecao() {
		check_admin_referer( 'ae_salvar_limite_excecao' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'agendar-entregas' ) );
		}

		$data     = isset( $_POST['data'] ) ? sanitize_text_field( wp_unslash( $_POST['data'] ) ) : '';
		$turno_id = isset( $_POST['turno_id'] ) ? absint( $_POST['turno_id'] ) : 0;
		$limite   = isset( $_POST['limite'] ) ? absint( $_POST['limite'] ) : -1;

		if ( empty( $data ) || empty( $turno_id ) || $limite < 0 ) {
			wp_safe_redirect( add_query_arg( 'ae_erro', '1', wp_get_referer() ) );
			exit;
		}

		AE_Limite_Excecao::definir( $data, $turno_id, $limite );

		wp_safe_redirect( admin_url( 'admin.php?page=ae-dias-bloqueados&ae_sucesso=1' ) );
		exit;
	}

	public function excluir_limite_excecao() {
		check_admin_referer( 'ae_excluir_limite_excecao' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'agendar-entregas' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( $id ) {
			AE_Limite_Excecao::remover( $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ae-dias-bloqueados&ae_sucesso=1' ) );
		exit;
	}

	public function salvar_dias_semana_bloqueados() {
		check_admin_referer( 'ae_salvar_dias_semana_bloqueados' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'agendar-entregas' ) );
		}

		$dias_semana = isset( $_POST['dias_semana_bloqueados'] ) ? array_map( 'absint', (array) $_POST['dias_semana_bloqueados'] ) : array();

		AE_Dias_Bloqueados::salvar_dias_semana_bloqueados( $dias_semana );

		wp_safe_redirect( admin_url( 'admin.php?page=ae-dias-bloqueados&ae_sucesso=1' ) );
		exit;
	}

	public function salvar_configuracoes() {
		check_admin_referer( 'ae_salvar_configuracoes' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'agendar-entregas' ) );
		}

		$dias_preparo = isset( $_POST['ae_dias_preparo'] ) ? absint( $_POST['ae_dias_preparo'] ) : 0;

		update_option( 'ae_dias_preparo', $dias_preparo );

		// Checkbox desmarcado não é enviado no POST - ausência = desligado.
		update_option( 'ae_checkout_habilitado', isset( $_POST['ae_checkout_habilitado'] ) ? '1' : '0' );

		$status_calendario = isset( $_POST['ae_status_calendario'] )
			? array_map( 'sanitize_key', (array) wp_unslash( $_POST['ae_status_calendario'] ) )
			: array();
		update_option( 'ae_status_calendario', $status_calendario );

		wp_safe_redirect( admin_url( 'admin.php?page=ae-turnos&ae_sucesso=1' ) );
		exit;
	}

	public function salvar_turno() {
		check_admin_referer( 'ae_salvar_turno' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'agendar-entregas' ) );
		}

		$nome           = isset( $_POST['nome'] ) ? sanitize_text_field( wp_unslash( $_POST['nome'] ) ) : '';
		$hora_inicio    = isset( $_POST['hora_inicio'] ) ? sanitize_text_field( wp_unslash( $_POST['hora_inicio'] ) ) : '';
		$hora_fim       = isset( $_POST['hora_fim'] ) ? sanitize_text_field( wp_unslash( $_POST['hora_fim'] ) ) : '';
		$limite_por_dia = isset( $_POST['limite_por_dia'] ) ? absint( $_POST['limite_por_dia'] ) : 0;
		$dias_semana    = isset( $_POST['dias_semana'] ) ? array_map( 'absint', (array) $_POST['dias_semana'] ) : array();
		$metodos        = isset( $_POST['metodos_entrega'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['metodos_entrega'] ) ) : array();
		$turno_id       = isset( $_POST['turno_id'] ) ? absint( $_POST['turno_id'] ) : 0;

		if ( empty( $nome ) || empty( $hora_inicio ) || empty( $hora_fim ) || $limite_por_dia < 1 ) {
			wp_safe_redirect( add_query_arg( 'ae_erro', '1', wp_get_referer() ) );
			exit;
		}

		$dados_post = array(
			'post_type'   => AE_CPT_Turno::POST_TYPE,
			'post_title'  => $nome,
			'post_status' => 'publish',
		);

		if ( $turno_id ) {
			$dados_post['ID'] = $turno_id;
			wp_update_post( $dados_post );
		} else {
			$turno_id = wp_insert_post( $dados_post );
		}

		update_post_meta( $turno_id, 'hora_inicio', $hora_inicio );
		update_post_meta( $turno_id, 'hora_fim', $hora_fim );
		update_post_meta( $turno_id, 'limite_por_dia', $limite_por_dia );
		update_post_meta( $turno_id, 'dias_semana', $dias_semana );
		update_post_meta( $turno_id, 'metodos_entrega', $metodos );

		wp_safe_redirect( admin_url( 'admin.php?page=ae-turnos&ae_sucesso=1' ) );
		exit;
	}

	/**
	 * Vincula, de uma vez, vários turnos a um método de entrega. Substitui o
	 * grupo inteiro: turnos marcados passam a incluir o método, turnos
	 * desmarcados deixam de tê-lo (mantendo os demais métodos que já tinham).
	 */
	public function salvar_grupo_metodo() {
		check_admin_referer( 'ae_salvar_grupo_metodo' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'agendar-entregas' ) );
		}

		$metodo          = isset( $_POST['metodo'] ) ? sanitize_text_field( wp_unslash( $_POST['metodo'] ) ) : '';
		$turnos_marcados = isset( $_POST['turnos'] ) ? array_map( 'absint', (array) $_POST['turnos'] ) : array();

		if ( empty( $metodo ) ) {
			wp_safe_redirect( add_query_arg( 'ae_erro', '1', wp_get_referer() ) );
			exit;
		}

		foreach ( AE_CPT_Turno::listar_turnos() as $turno ) {
			$metodos_atuais = $turno->metodos_entrega;
			$deve_ter       = in_array( $turno->id, $turnos_marcados, true );
			$tem_atualmente = in_array( $metodo, $metodos_atuais, true );

			if ( $deve_ter && ! $tem_atualmente ) {
				$metodos_atuais[] = $metodo;
				update_post_meta( $turno->id, 'metodos_entrega', $metodos_atuais );
			} elseif ( ! $deve_ter && $tem_atualmente ) {
				$metodos_atuais = array_diff( $metodos_atuais, array( $metodo ) );
				update_post_meta( $turno->id, 'metodos_entrega', array_values( $metodos_atuais ) );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ae-turnos&ae_sucesso=1' ) );
		exit;
	}

	public function excluir_turno() {
		check_admin_referer( 'ae_excluir_turno' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'agendar-entregas' ) );
		}

		$turno_id = isset( $_GET['turno_id'] ) ? absint( $_GET['turno_id'] ) : 0;

		if ( $turno_id ) {
			wp_delete_post( $turno_id, true );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ae-turnos&ae_sucesso=1' ) );
		exit;
	}

	public function salvar_dia_bloqueado() {
		check_admin_referer( 'ae_salvar_dia_bloqueado' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'agendar-entregas' ) );
		}

		$data   = isset( $_POST['data'] ) ? sanitize_text_field( wp_unslash( $_POST['data'] ) ) : '';
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';

		if ( empty( $data ) ) {
			wp_safe_redirect( add_query_arg( 'ae_erro', '1', wp_get_referer() ) );
			exit;
		}

		AE_Dias_Bloqueados::adicionar( $data, $motivo );

		wp_safe_redirect( admin_url( 'admin.php?page=ae-dias-bloqueados&ae_sucesso=1' ) );
		exit;
	}

	public function excluir_dia_bloqueado() {
		check_admin_referer( 'ae_excluir_dia_bloqueado' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'agendar-entregas' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( $id ) {
			AE_Dias_Bloqueados::remover( $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ae-dias-bloqueados&ae_sucesso=1' ) );
		exit;
	}
}
