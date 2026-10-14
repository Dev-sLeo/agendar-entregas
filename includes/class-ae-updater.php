<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configura o Plugin Update Checker (https://github.com/YahnisElsts/plugin-update-checker)
 * para que o plugin se atualize sozinho a partir das releases do GitHub,
 * aparecendo normalmente em Painel > Plugins — sem depender do WordPress.org.
 *
 * Fluxo esperado no repositório: criar uma tag/release no GitHub (ex.: v1.2.0)
 * com o mesmo número do "Version" do cabeçalho do plugin. O texto da release
 * vira o changelog exibido no popup "Ver detalhes da versão".
 */
class AE_Updater {

	const REPO_URL = 'https://github.com/Dev-sLeo/agendar-entregas';

	public static function registrar() {
		if ( ! class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
			return;
		}

		$updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			AE_PLUGIN_FILE,
			'agendar-entregas'
		);

		// Por padrão o Plugin Update Checker lê as tags do repositório (não
		// exige publicar uma "Release" separada, basta dar `git tag`+`push`).

		// Repositório privado: definir no wp-config.php o token de acesso
		// pessoal do GitHub (permissão "repo").
		if ( defined( 'AE_GITHUB_TOKEN' ) && AE_GITHUB_TOKEN ) {
			$updater->setAuthentication( AE_GITHUB_TOKEN );
		}
	}
}
