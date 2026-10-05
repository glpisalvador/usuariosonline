<?php

/**
 * Plugin Usuários Online - instalação e desinstalação
 */

function plugin_usuariosonline_install(): bool
{
    global $DB;

    require_once __DIR__ . '/inc/config.class.php';
    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // ------------------------------------------------------------ configurações (chave/valor)
    if (!$DB->tableExists('glpi_plugin_usuariosonline_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_usuariosonline_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` text NULL,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }
    // 1.x guardava os perfis com acesso em "perfis_permitidos"
    $antigo = PluginUsuariosonlineConfig::getConfig('perfis_permitidos', '');
    foreach (PluginUsuariosonlineConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_usuariosonline_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            if ($nome === 'perfis_ver' && $antigo !== '' && $antigo !== null) {
                $valor = json_decode((string) $antigo, true) ?: [];
            }
            $DB->insert('glpi_plugin_usuariosonline_configs', ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor) : (string) $valor]);
        }
    }

    // ------------------------------------------------------------ último sinal de cada pessoa (presença agora)
    if (!$DB->tableExists('glpi_plugin_usuariosonline_sinais')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_usuariosonline_sinais` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `profiles_id` int unsigned NOT NULL DEFAULT 0,
            `estado` varchar(10) NOT NULL DEFAULT 'ativo',
            `online_desde` timestamp NULL DEFAULT NULL,
            `ultima_atividade` timestamp NULL DEFAULT NULL,
            `ultimo_sinal` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`),
            KEY `ultimo_sinal` (`ultimo_sinal`)
        ) $opcoes");
    }

    // ------------------------------------------------------------ histórico diário de presença
    if (!$DB->tableExists('glpi_plugin_usuariosonline_historico')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_usuariosonline_historico` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `data` date NOT NULL,
            `primeiro_acesso` timestamp NULL DEFAULT NULL,
            `ultimo_acesso` timestamp NULL DEFAULT NULL,
            `segundos_online` int unsigned NOT NULL DEFAULT 0,
            `segundos_ativos` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `usuario_data` (`users_id`, `data`),
            KEY `data` (`data`)
        ) $opcoes");
    }

    // ------------------------------------------------------------ limpeza diária do histórico antigo
    CronTask::register('PluginUsuariosonlinePresenca', 'UsuariosonlineLimpar', DAY_TIMESTAMP, [
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'hourmin' => 0,
        'hourmax' => 24,
        'comment' => 'Remove o histórico de presença mais antigo que o prazo de retenção',
    ]);

    return true;
}

function plugin_usuariosonline_uninstall(): bool
{
    // Regra do projeto: as tabelas ficam (reinstalar recupera configuração e histórico)
    return true;
}
