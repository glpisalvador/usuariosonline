<?php

/**
 * Plugin Usuários Online - GLPI 11 e 12
 * Mostra quem está usando o GLPI agora (ativo ou ausente), com os chamados de cada pessoa,
 * num painel na barra superior e numa página em Ferramentas, e guarda o histórico diário de
 * presença (primeiro e último acesso e tempo ativo).
 */

define('PLUGIN_USUARIOSONLINE_VERSION', '2.0.0');
define('PLUGIN_USUARIOSONLINE_MIN_GLPI', '11.0.0');
define('PLUGIN_USUARIOSONLINE_MAX_GLPI', '12.99.99');

function plugin_init_usuariosonline(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['usuariosonline'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('usuariosonline')) {
        return;
    }

    Plugin::registerClass('PluginUsuariosonlineMenu');
    Plugin::registerClass('PluginUsuariosonlinePresenca');
    $PLUGIN_HOOKS['config_page']['usuariosonline'] = 'front/config.form.php';

    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['menu_toadd']['usuariosonline'] = ['tools' => 'PluginUsuariosonlineMenu'];
        // Todo usuário logado avisa que está online; o painel só aparece para quem pode ver
        $PLUGIN_HOOKS['add_css']['usuariosonline'] = ['css/usuariosonline.css'];
        $PLUGIN_HOOKS['add_javascript']['usuariosonline'] = ['js/usuariosonline.js'];
    }
}

function plugin_version_usuariosonline(): array
{
    return [
        'name'         => 'Usuários Online',
        'version'      => PLUGIN_USUARIOSONLINE_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_USUARIOSONLINE_MIN_GLPI,
                'max' => PLUGIN_USUARIOSONLINE_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_usuariosonline_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_USUARIOSONLINE_MIN_GLPI, '>=');
}

function plugin_usuariosonline_check_config($verbose = false): bool
{
    return true;
}
