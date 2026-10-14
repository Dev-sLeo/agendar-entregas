<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$turnos     = AE_CPT_Turno::listar_turnos();
$dias_label = array(
	1 => __( 'Segunda', 'agendar-entregas' ),
	2 => __( 'Terça', 'agendar-entregas' ),
	3 => __( 'Quarta', 'agendar-entregas' ),
	4 => __( 'Quinta', 'agendar-entregas' ),
	5 => __( 'Sexta', 'agendar-entregas' ),
	6 => __( 'Sábado', 'agendar-entregas' ),
	0 => __( 'Domingo', 'agendar-entregas' ),
);
$turnos_orfaos = AE_Agendamentos::turnos_orfaos();

/**
 * Sugere o turno atual mais parecido com o salvo no agendamento órfão, pra
 * pré-selecionar no <select> - admin ainda confirma/troca antes de salvar.
 *
 * Valida pelo nome E pelo horário: turnos diferentes podem ter o mesmo nome
 * (ex.: "Manhã" em duas lojas/unidades com horários distintos), então bater
 * o horário exatamente vale muito mais na pontuação do que só a semelhança
 * do nome - evita sugerir o turno errado quando o nome sozinho é ambíguo.
 */
$sugerir_turno = function ( $orfao ) use ( $turnos ) {
	$melhor_id   = 0;
	$melhor_nota = -1;

	foreach ( $turnos as $turno ) {
		similar_text( mb_strtolower( $orfao->turno_nome ), mb_strtolower( $turno->nome ), $nota );

		$horario_bate = ! empty( $orfao->turno_hora_inicio )
			&& $orfao->turno_hora_inicio === $turno->hora_inicio
			&& $orfao->turno_hora_fim === $turno->hora_fim;

		if ( $horario_bate ) {
			$nota += 1000;
		}

		if ( $nota > $melhor_nota ) {
			$melhor_nota = $nota;
			$melhor_id   = $turno->id;
		}
	}

	return $melhor_id;
};

$metodos_entrega   = AE_Disponibilidade::metodos_entrega_disponiveis();
$status_calendario = AE_Disponibilidade::status_calendario();
$status_disponiveis = array();
foreach ( wc_get_order_statuses() as $chave => $rotulo ) {
	$status_disponiveis[ str_replace( 'wc-', '', $chave ) ] = $rotulo;
}

$editando_id = isset( $_GET['editar'] ) ? absint( $_GET['editar'] ) : 0;
$editando    = $editando_id ? AE_CPT_Turno::obter_turno( $editando_id ) : null;

$input_class = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
$label_class = 'block text-sm font-medium text-gray-700 mb-1';
$botao_class = 'inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500';
?>
<div class="wrap ae-painel">
	<div class="max-w-5xl">
		<h1 class="text-2xl font-semibold text-gray-900 mb-6"><?php esc_html_e( 'Turnos de Entrega', 'agendar-entregas' ); ?></h1>

		<?php if ( isset( $_GET['ae_sucesso'] ) ) : ?>
			<div class="mb-6 rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
				<?php esc_html_e( 'Salvo com sucesso.', 'agendar-entregas' ); ?>
			</div>
		<?php endif; ?>

		<?php if ( isset( $_GET['ae_erro'] ) ) : ?>
			<div class="mb-6 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
				<?php esc_html_e( 'Verifique os campos e tente novamente.', 'agendar-entregas' ); ?>
			</div>
		<?php endif; ?>

		<div class="bg-white rounded-lg border border-gray-200 shadow-sm mb-8">
			<div class="px-6 pt-6">
				<h2 class="text-lg font-semibold text-gray-900 mb-4"><?php esc_html_e( 'Configurações', 'agendar-entregas' ); ?></h2>
				<nav class="flex gap-6 border-b border-gray-200" role="tablist">
					<button type="button" class="ae-tab-botao pb-3 text-sm font-medium border-b-2" data-ae-tab="geral">
						<?php esc_html_e( 'Geral', 'agendar-entregas' ); ?>
					</button>
					<button type="button" class="ae-tab-botao pb-3 text-sm font-medium border-b-2" data-ae-tab="calendario">
						<?php esc_html_e( 'Calendário', 'agendar-entregas' ); ?>
					</button>
				</nav>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="p-6">
				<?php wp_nonce_field( 'ae_salvar_configuracoes' ); ?>
				<input type="hidden" name="action" value="ae_salvar_configuracoes" />

				<div data-ae-tab-painel="geral" class="max-w-sm">
					<label for="ae_dias_preparo" class="<?php echo esc_attr( $label_class ); ?>">
						<?php esc_html_e( 'Dias de espera para preparação', 'agendar-entregas' ); ?>
					</label>
					<input type="number" name="ae_dias_preparo" id="ae_dias_preparo" min="0" step="1"
						value="<?php echo esc_attr( AE_Disponibilidade::dias_preparo() ); ?>"
						class="<?php echo esc_attr( $input_class ); ?>" />
					<p class="mt-2 text-xs text-gray-500">
						<?php esc_html_e( 'Quantidade de dias necessários antes que uma entrega possa ser agendada. Ex.: com 1 dia de espera, um pedido feito hoje só pode ser entregue depois de amanhã.', 'agendar-entregas' ); ?>
					</p>

					<div class="mt-5 pt-5 border-t border-gray-100">
						<label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
							<input type="checkbox" name="ae_checkout_habilitado" value="1"
								<?php checked( AE_Disponibilidade::habilitado_no_checkout() ); ?>
								class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
							<?php esc_html_e( 'Mostrar campos de agendamento no checkout', 'agendar-entregas' ); ?>
						</label>
						<p class="mt-1 text-xs text-gray-500">
							<?php esc_html_e( 'Desligar isso só esconde os campos de data/turno para o cliente no site - o admin ainda pode definir a entrega manualmente dentro de cada pedido.', 'agendar-entregas' ); ?>
						</p>
					</div>
				</div>

				<div data-ae-tab-painel="calendario" class="hidden">
					<span class="<?php echo esc_attr( $label_class ); ?>">
						<?php esc_html_e( 'Status do pedido que entram no calendário', 'agendar-entregas' ); ?>
					</span>
					<p class="text-xs text-gray-500 mb-3">
						<?php esc_html_e( 'O pedido só aparece no calendário (e ocupa a vaga do turno) quando estiver em um destes status. Por padrão: Processando e Concluído.', 'agendar-entregas' ); ?>
					</p>
					<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
						<?php foreach ( $status_disponiveis as $chave => $rotulo ) : ?>
							<label class="flex items-center gap-2 text-sm text-gray-700">
								<input type="checkbox" name="ae_status_calendario[]" value="<?php echo esc_attr( $chave ); ?>"
									<?php checked( in_array( $chave, $status_calendario, true ) ); ?>
									class="shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
								<?php echo esc_html( $rotulo ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<button type="submit" class="<?php echo esc_attr( $botao_class ); ?> mt-6">
					<?php esc_html_e( 'Salvar configurações', 'agendar-entregas' ); ?>
				</button>
			</form>
		</div>

		<script>
			( function () {
				var botoes = document.querySelectorAll( '.ae-tab-botao' );
				var ativa = function ( nome ) {
					botoes.forEach( function ( botao ) {
						var selecionado = botao.getAttribute( 'data-ae-tab' ) === nome;
						botao.classList.toggle( 'border-indigo-600', selecionado );
						botao.classList.toggle( 'text-indigo-600', selecionado );
						botao.classList.toggle( 'border-transparent', ! selecionado );
						botao.classList.toggle( 'text-gray-500', ! selecionado );
					} );
					document.querySelectorAll( '[data-ae-tab-painel]' ).forEach( function ( painel ) {
						painel.classList.toggle( 'hidden', painel.getAttribute( 'data-ae-tab-painel' ) !== nome );
					} );
				};
				botoes.forEach( function ( botao ) {
					botao.addEventListener( 'click', function () {
						ativa( botao.getAttribute( 'data-ae-tab' ) );
					} );
				} );
				ativa( 'geral' );
			} )();
		</script>

		<div id="ae-form-turno" class="bg-white rounded-lg border border-gray-200 shadow-sm p-6 mb-8">
			<div class="flex items-center justify-between mb-4">
				<h2 class="text-lg font-semibold text-gray-900">
					<?php echo $editando ? esc_html__( 'Editar turno', 'agendar-entregas' ) : esc_html__( 'Novo turno', 'agendar-entregas' ); ?>
				</h2>
				<?php if ( $editando ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ae-turnos' ) ); ?>" class="text-sm text-gray-500 hover:text-gray-700">
						<?php esc_html_e( 'Cancelar edição', 'agendar-entregas' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ae_salvar_turno' ); ?>
				<input type="hidden" name="action" value="ae_salvar_turno" />
				<?php if ( $editando ) : ?>
					<input type="hidden" name="turno_id" value="<?php echo esc_attr( $editando->id ); ?>" />
				<?php endif; ?>

				<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
					<div>
						<label for="nome" class="<?php echo esc_attr( $label_class ); ?>"><?php esc_html_e( 'Nome', 'agendar-entregas' ); ?></label>
						<input type="text" name="nome" id="nome" required class="<?php echo esc_attr( $input_class ); ?>"
							value="<?php echo esc_attr( $editando ? $editando->nome : '' ); ?>" />
					</div>
					<div>
						<label for="limite_por_dia" class="<?php echo esc_attr( $label_class ); ?>"><?php esc_html_e( 'Limite de agendamentos', 'agendar-entregas' ); ?></label>
						<input type="number" name="limite_por_dia" id="limite_por_dia" min="1" required class="<?php echo esc_attr( $input_class ); ?>"
							value="<?php echo esc_attr( $editando ? $editando->limite_por_dia : '' ); ?>" />
					</div>
					<div>
						<label for="hora_inicio" class="<?php echo esc_attr( $label_class ); ?>"><?php esc_html_e( 'Hora início', 'agendar-entregas' ); ?></label>
						<input type="time" name="hora_inicio" id="hora_inicio" required class="<?php echo esc_attr( $input_class ); ?>"
							value="<?php echo esc_attr( $editando ? $editando->hora_inicio : '' ); ?>" />
					</div>
					<div>
						<label for="hora_fim" class="<?php echo esc_attr( $label_class ); ?>"><?php esc_html_e( 'Hora fim', 'agendar-entregas' ); ?></label>
						<input type="time" name="hora_fim" id="hora_fim" required class="<?php echo esc_attr( $input_class ); ?>"
							value="<?php echo esc_attr( $editando ? $editando->hora_fim : '' ); ?>" />
					</div>
				</div>

				<div class="mb-6">
					<span class="<?php echo esc_attr( $label_class ); ?>"><?php esc_html_e( 'Dias da semana', 'agendar-entregas' ); ?></span>
					<div class="flex flex-wrap gap-4">
						<?php foreach ( $dias_label as $valor => $label ) : ?>
							<label class="inline-flex items-center gap-2 text-sm text-gray-700">
								<input type="checkbox" name="dias_semana[]" value="<?php echo esc_attr( $valor ); ?>"
									<?php checked( $editando && in_array( $valor, $editando->dias_semana, true ) ); ?>
									class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="mb-6">
					<span class="<?php echo esc_attr( $label_class ); ?>"><?php esc_html_e( 'Métodos de entrega', 'agendar-entregas' ); ?></span>
					<p class="text-xs text-gray-500 mb-2">
						<?php esc_html_e( 'Se nenhum for marcado, o turno fica disponível para qualquer método de entrega.', 'agendar-entregas' ); ?>
					</p>
					<?php if ( empty( $metodos_entrega ) ) : ?>
						<p class="text-sm text-gray-500"><?php esc_html_e( 'Nenhum método de entrega configurado no WooCommerce.', 'agendar-entregas' ); ?></p>
					<?php else : ?>
						<div class="flex flex-wrap gap-4">
							<?php foreach ( $metodos_entrega as $chave => $label ) : ?>
								<label class="inline-flex items-center gap-2 text-sm text-gray-700">
									<input type="checkbox" name="metodos_entrega[]" value="<?php echo esc_attr( $chave ); ?>"
										<?php checked( $editando && in_array( $chave, $editando->metodos_entrega, true ) ); ?>
										class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<button type="submit" class="<?php echo esc_attr( $botao_class ); ?>">
					<?php echo $editando ? esc_html__( 'Atualizar turno', 'agendar-entregas' ) : esc_html__( 'Salvar turno', 'agendar-entregas' ); ?>
				</button>
			</form>
		</div>

		<?php if ( ! empty( $turnos_orfaos ) ) : ?>
			<div id="ae-turnos-orfaos" class="bg-white rounded-lg border border-amber-300 shadow-sm p-6 mb-8">
				<h2 class="text-lg font-semibold text-gray-900 mb-1"><?php esc_html_e( 'Corrigir turnos órfãos', 'agendar-entregas' ); ?></h2>
				<p class="text-xs text-gray-500 mb-4">
					<?php esc_html_e( 'Estes agendamentos apontam para um turno que não existe mais (ex.: excluído e recriado com outro ID, ou perdido num deploy/restore). As datas continuam corretas - escolha para qual turno atual cada um deve ser reatribuído, com base no nome e horário salvos na época (dois turnos podem ter o mesmo nome com horários diferentes).', 'agendar-entregas' ); ?>
				</p>

				<div class="space-y-3">
					<?php foreach ( $turnos_orfaos as $orfao ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							class="flex flex-wrap items-center gap-3 rounded-md bg-amber-50 border border-amber-200 px-4 py-3">
							<?php wp_nonce_field( 'ae_reatribuir_turno_orfao' ); ?>
							<input type="hidden" name="action" value="ae_reatribuir_turno_orfao" />
							<input type="hidden" name="turno_id_antigo" value="<?php echo esc_attr( $orfao->turno_id ); ?>" />

							<span class="text-sm text-gray-700">
								<strong><?php echo esc_html( $orfao->turno_nome ?: __( '(sem nome salvo)', 'agendar-entregas' ) ); ?></strong>
								<?php if ( ! empty( $orfao->turno_hora_inicio ) ) : ?>
									<span class="text-gray-500">(<?php echo esc_html( $orfao->turno_hora_inicio . ' - ' . $orfao->turno_hora_fim ); ?>)</span>
								<?php endif; ?>
								<?php
								printf(
									/* translators: 1: ID do turno antigo, 2: quantidade de agendamentos */
									esc_html__( '— ID antigo #%1$d, %2$d agendamento(s)', 'agendar-entregas' ),
									(int) $orfao->turno_id,
									(int) $orfao->total
								);
								?>
							</span>

							<span class="text-sm text-gray-400">→</span>

							<select name="turno_id_novo" required class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
								<option value=""><?php esc_html_e( 'Selecione o turno correto…', 'agendar-entregas' ); ?></option>
								<?php $sugestao_id = $sugerir_turno( $orfao ); ?>
								<?php foreach ( $turnos as $turno ) : ?>
									<option value="<?php echo esc_attr( $turno->id ); ?>" <?php selected( $sugestao_id, $turno->id ); ?>>
										<?php echo esc_html( $turno->nome . ' (' . $turno->hora_inicio . ' - ' . $turno->hora_fim . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>

							<button type="submit" class="inline-flex items-center rounded-md bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-500">
								<?php esc_html_e( 'Reatribuir', 'agendar-entregas' ); ?>
							</button>
						</form>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $metodos_entrega ) && ! empty( $turnos ) ) : ?>
			<div class="bg-white rounded-lg border border-gray-200 shadow-sm p-6 mb-8">
				<h2 class="text-lg font-semibold text-gray-900 mb-1"><?php esc_html_e( 'Vincular turnos a um método de entrega', 'agendar-entregas' ); ?></h2>
				<p class="text-xs text-gray-500 mb-4">
					<?php esc_html_e( 'Escolha um método e marque todos os turnos que devem pertencer a ele de uma vez. Turnos desmarcados são removidos desse método (mas mantêm os demais).', 'agendar-entregas' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ae_salvar_grupo_metodo' ); ?>
					<input type="hidden" name="action" value="ae_salvar_grupo_metodo" />

					<div class="mb-4 max-w-sm">
						<label for="ae_metodo_grupo" class="<?php echo esc_attr( $label_class ); ?>"><?php esc_html_e( 'Método de entrega', 'agendar-entregas' ); ?></label>
						<select name="metodo" id="ae_metodo_grupo" required class="<?php echo esc_attr( $input_class ); ?>" onchange="aeAtualizarGrupoTurnos(this)">
							<option value=""><?php esc_html_e( 'Selecione…', 'agendar-entregas' ); ?></option>
							<?php foreach ( $metodos_entrega as $chave => $label ) : ?>
								<option value="<?php echo esc_attr( $chave ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="mb-4 flex flex-wrap gap-4">
						<?php foreach ( $turnos as $turno ) : ?>
							<label class="inline-flex items-center gap-2 text-sm text-gray-700">
								<input type="checkbox" name="turnos[]" value="<?php echo esc_attr( $turno->id ); ?>"
									class="ae-checkbox-turno rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
									data-metodos="<?php echo esc_attr( wp_json_encode( $turno->metodos_entrega ) ); ?>" />
								<?php echo esc_html( $turno->nome . ' (' . $turno->hora_inicio . ' - ' . $turno->hora_fim . ')' ); ?>
							</label>
						<?php endforeach; ?>
					</div>

					<button type="submit" class="<?php echo esc_attr( $botao_class ); ?>">
						<?php esc_html_e( 'Salvar vínculo', 'agendar-entregas' ); ?>
					</button>
				</form>
			</div>

			<script>
				function aeAtualizarGrupoTurnos( select ) {
					var metodo = select.value;
					document.querySelectorAll( '.ae-checkbox-turno' ).forEach( function ( checkbox ) {
						var metodos = JSON.parse( checkbox.getAttribute( 'data-metodos' ) || '[]' );
						checkbox.checked = metodo && metodos.indexOf( metodo ) !== -1;
					} );
				}
			</script>
		<?php endif; ?>

		<div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
			<h2 class="text-lg font-semibold text-gray-900 px-6 pt-6 pb-4"><?php esc_html_e( 'Turnos cadastrados', 'agendar-entregas' ); ?></h2>
			<table class="min-w-full divide-y divide-gray-200 text-sm">
				<thead class="bg-gray-50">
					<tr>
						<th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wide"><?php esc_html_e( 'Nome', 'agendar-entregas' ); ?></th>
						<th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wide"><?php esc_html_e( 'Horário', 'agendar-entregas' ); ?></th>
						<th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wide"><?php esc_html_e( 'Limite/dia', 'agendar-entregas' ); ?></th>
						<th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wide"><?php esc_html_e( 'Dias', 'agendar-entregas' ); ?></th>
						<th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wide"><?php esc_html_e( 'Métodos de entrega', 'agendar-entregas' ); ?></th>
						<th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wide"><?php esc_html_e( 'Ações', 'agendar-entregas' ); ?></th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100">
					<?php if ( empty( $turnos ) ) : ?>
						<tr><td colspan="6" class="px-6 py-4 text-gray-500"><?php esc_html_e( 'Nenhum turno cadastrado.', 'agendar-entregas' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $turnos as $turno ) : ?>
						<tr>
							<td class="px-6 py-3 text-gray-900"><?php echo esc_html( $turno->nome ); ?></td>
							<td class="px-6 py-3 text-gray-700"><?php echo esc_html( $turno->hora_inicio . ' - ' . $turno->hora_fim ); ?></td>
							<td class="px-6 py-3 text-gray-700"><?php echo esc_html( $turno->limite_por_dia ); ?></td>
							<td class="px-6 py-3 text-gray-700">
								<?php
								$nomes_dias = array_map(
									function ( $d ) use ( $dias_label ) {
										return isset( $dias_label[ $d ] ) ? $dias_label[ $d ] : '';
									},
									$turno->dias_semana
								);
								echo esc_html( implode( ', ', array_filter( $nomes_dias ) ) );
								?>
							</td>
							<td class="px-6 py-3 text-gray-700">
								<?php
								if ( empty( $turno->metodos_entrega ) ) {
									esc_html_e( 'Todos', 'agendar-entregas' );
								} else {
									$nomes_metodos = array_map(
										function ( $chave ) use ( $metodos_entrega ) {
											return isset( $metodos_entrega[ $chave ] ) ? $metodos_entrega[ $chave ] : $chave;
										},
										$turno->metodos_entrega
									);
									echo esc_html( implode( ', ', $nomes_metodos ) );
								}
								?>
							</td>
							<td class="px-6 py-3 whitespace-nowrap">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=ae-turnos&editar=' . $turno->id . '#ae-form-turno' ) ); ?>"
									class="text-indigo-600 hover:text-indigo-800 font-medium mr-4">
									<?php esc_html_e( 'Editar', 'agendar-entregas' ); ?>
								</a>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ae_excluir_turno&turno_id=' . $turno->id ), 'ae_excluir_turno' ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Excluir este turno?', 'agendar-entregas' ) ); ?>');"
									class="text-red-600 hover:text-red-800 font-medium">
									<?php esc_html_e( 'Excluir', 'agendar-entregas' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
