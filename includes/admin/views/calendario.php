<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap ae-painel">
	<div class="max-w-7xl">
		<h1 class="text-2xl font-semibold text-gray-900 mb-3"><?php esc_html_e( 'Calendário de Entregas', 'agendar-entregas' ); ?></h1>

		<div class="flex items-center gap-3 mb-4">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ae_sincronizar_pedidos' ), 'ae_sincronizar_pedidos' ) ); ?>"
				class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
				title="<?php esc_attr_e( 'Recria agendamentos de pedidos já em processando/concluído (ex.: pagamento na entrega) que não apareceram na agenda.', 'agendar-entregas' ); ?>">
				<?php esc_html_e( 'Sincronizar pedidos antigos', 'agendar-entregas' ); ?>
			</a>
			<button type="button" id="ae-atualizar-calendario"
				class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-60">
				<?php esc_html_e( 'Atualizar calendário', 'agendar-entregas' ); ?>
			</button>
		</div>

		<?php if ( isset( $_GET['ae_sincronizados'] ) ) : ?>
			<div class="mb-4 rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
				<?php
				printf(
					/* translators: %d: quantidade de pedidos sincronizados */
					esc_html__( '%d pedido(s) sincronizado(s) com sucesso.', 'agendar-entregas' ),
					absint( $_GET['ae_sincronizados'] )
				);
				?>
			</div>
		<?php endif; ?>

		<p id="ae-calendario-sincronizado" class="text-xs text-gray-500 mb-4"></p>

		<div id="ae-calendario-aviso" class="hidden mb-4 rounded-md px-4 py-3 text-sm"></div>

		<div id="ae-calendario" class="ae-calendario bg-white rounded-lg border border-gray-200 shadow-sm p-6 text-base"></div>

		<div id="ae-modal-dia" class="hidden fixed inset-0 bg-black/50 z-[100000]">
			<div id="ae-modal-conteudo" class="bg-white max-w-md mx-auto mt-20 p-6 rounded-lg shadow-lg max-h-[80vh] flex flex-col">
				<h2 id="ae-modal-titulo" class="text-lg font-semibold text-gray-900 mb-4 shrink-0"></h2>
				<ul id="ae-modal-lista" class="space-y-2 text-sm text-gray-700 mb-4 overflow-y-auto"></ul>
				<button type="button" id="ae-modal-fechar"
					class="inline-flex items-center rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 shrink-0 self-start">
					<?php esc_html_e( 'Fechar', 'agendar-entregas' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>
