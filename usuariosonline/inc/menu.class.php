<?php

/**
 * Plugin Usuários Online - item no menu Ferramentas
 */
class PluginUsuariosonlineMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Usuários online';
    }

    public static function getMenuName(): string
    {
        return 'Usuários online';
    }

    public static function getIcon(): string
    {
        return 'ti ti-users';
    }

    public static function canView(): bool
    {
        return PluginUsuariosonlineConfig::podeVer();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $C = PluginUsuariosonlineConfig::class;
        $menu = [
            'title' => self::getMenuName(),
            'page'  => $C::url('online.php'),
            'icon'  => self::getIcon(),
            'links' => ['search' => $C::url('online.php')],
        ];
        if ($C::ehAdmin()) {
            $menu['links']['config'] = $C::url('config.form.php');
        }
        return $menu;
    }
}
