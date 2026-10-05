<?php

/**
 * Plugin Usuários Online - configurações e utilitários comuns
 */
class PluginUsuariosonlineConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_usuariosonline_configs';

    public static function getTypeName($nb = 0): string
    {
        return 'Usuários Online';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    /** Perfil ativo pode ver o painel e a página (lista vazia = quem tem direito de configuração) */
    public static function podeVer(): bool
    {
        if (!Session::getLoginUserID()) {
            return false;
        }
        $perfis = self::ids('perfis_ver');
        if (!$perfis) {
            return (bool) Session::haveRight('config', READ);
        }
        return in_array((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0), $perfis, true);
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'perfis_ver'    => [],
            'perfis_listar' => [],
            'grupos'        => [],
            'sem_grupo'     => '1',
            'contadores'    => '1',
            'intervalo'     => '30',
            'ausente_min'   => '5',
            'offline_seg'   => '90',
            'retencao'      => '180',
        ];
    }

    private static ?array $usuariosonlineConfigs = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$usuariosonlineConfigs === null) {
            self::$usuariosonlineConfigs = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$usuariosonlineConfigs[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$usuariosonlineConfigs)) {
            return self::$usuariosonlineConfigs[$name];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$usuariosonlineConfigs = null;
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value), JSON_UNESCAPED_UNICODE));
    }

    public static function ids(string $name): array
    {
        return array_values(array_unique(array_filter(array_map('intval', self::getArrayConfig($name)), fn($v) => $v > 0)));
    }

    /** Intervalo do sinal, em segundos (10 a 300) */
    public static function intervalo(): int
    {
        return max(10, min(300, (int) self::getConfig('intervalo')));
    }

    /** Sem sinal por mais que isto: offline (sempre maior que o intervalo) */
    public static function offline(): int
    {
        return max(self::intervalo() * 2 + 10, min(3600, (int) self::getConfig('offline_seg')));
    }

    public static function ausenteMinutos(): int
    {
        return max(1, min(120, (int) self::getConfig('ausente_min')));
    }

    // =====================================================================
    // Listas
    // =====================================================================

    /** glpi_profiles não tem is_deleted */
    public static function listarPerfis(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'interface'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $lista[(int) $r['id']] = ['rotulo' => (string) $r['name'], 'detalhe' => $r['interface'] === 'helpdesk' ? 'simplificada' : 'padrão'];
        }
        return $lista;
    }

    /** glpi_groups não tem is_deleted */
    public static function listarGrupos(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'completename'], 'FROM' => 'glpi_groups', 'ORDER' => 'completename ASC']) as $r) {
            $lista[(int) $r['id']] = (string) ($r['completename'] ?: $r['name']);
        }
        return $lista;
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/usuariosonline/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    /** "3 h 25 min", "12 min", "menos de 1 min" */
    public static function duracao(int $segundos): string
    {
        if ($segundos < 60) {
            return $segundos > 0 ? 'menos de 1 min' : '—';
        }
        $h = intdiv($segundos, 3600);
        $m = intdiv($segundos % 3600, 60);
        return ($h > 0 ? $h . ' h ' : '') . ($m > 0 || $h === 0 ? $m . ' min' : '');
    }

    /**
     * Multiselect com pesquisa, marcar todos e selecionados primeiro.
     * $opcoes: [valor => rótulo] ou [valor => ['rotulo' => ..., 'detalhe' => ...]]
     */
    public static function multiselect(string $name, array $opcoes, array $selecionados, string $placeholder = 'Selecione...'): string
    {
        $selecionados = array_map('strval', $selecionados);
        $itens = [];
        foreach ($opcoes as $valor => $o) {
            $o = is_array($o) ? $o : ['rotulo' => $o];
            $itens[] = ['valor' => (string) $valor, 'marcado' => in_array((string) $valor, $selecionados, true)] + $o + ['detalhe' => ''];
        }
        usort($itens, fn($a, $b) => [$b['marcado'], mb_strtolower($a['rotulo'])] <=> [$a['marcado'], mb_strtolower($b['rotulo'])]);
        $h = '<div class="usuariosonline-ms" data-usuariosonline-ms data-placeholder="' . self::e($placeholder) . '">';
        $h .= '<input type="hidden" name="' . self::e($name) . '[]" value="-1">';
        $h .= '<button type="button" class="usuariosonline-ms-cabecalho form-select form-select-sm" data-usuariosonline-ms-abrir><span class="usuariosonline-ms-texto"></span></button>';
        $h .= '<div class="usuariosonline-ms-dropdown" hidden>';
        $h .= '<div class="usuariosonline-ms-topo"><input type="text" class="form-control form-control-sm usuariosonline-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>';
        $h .= '<label class="usuariosonline-ms-todos"><input type="checkbox" class="usuariosonline-check" data-usuariosonline-ms-todos> Marcar/desmarcar todos</label>';
        $h .= '<div class="usuariosonline-ms-opcoes">';
        foreach ($itens as $i) {
            $h .= '<label class="usuariosonline-ms-opcao' . ($i['marcado'] ? ' selected' : '') . '" data-label="' . self::e(mb_strtolower($i['rotulo'] . ' ' . $i['detalhe'])) . '">'
                . '<input type="checkbox" class="usuariosonline-check" name="' . self::e($name) . '[]" value="' . self::e($i['valor']) . '"' . ($i['marcado'] ? ' checked' : '') . '>'
                . '<span class="usuariosonline-ms-rotulo">' . self::e($i['rotulo']) . ($i['detalhe'] !== '' ? ' <small>' . self::e($i['detalhe']) . '</small>' : '') . '</span>'
                . '</label>';
        }
        $h .= '</div></div><div class="usuariosonline-ms-contador"></div></div>';
        return $h;
    }

    public static function idsPost(string $campo): array
    {
        $ids = array_map('intval', (array) ($_POST[$campo] ?? []));
        return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    }
}
