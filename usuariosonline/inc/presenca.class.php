<?php

use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;

/**
 * Plugin Usuários Online - presença.
 * Cada aba do GLPI envia um sinal periódico (ativo ou ausente). O último sinal de cada pessoa
 * diz quem está online agora; o histórico diário soma o tempo online e o tempo ativo.
 */
class PluginUsuariosonlinePresenca extends CommonDBTM
{
    public const SINAIS = 'glpi_plugin_usuariosonline_sinais';
    public const HISTORICO = 'glpi_plugin_usuariosonline_historico';

    public static function getTypeName($nb = 0): string
    {
        return 'Usuários online';
    }

    public static function getTable($classname = null)
    {
        return self::SINAIS;
    }

    public static function canView(): bool
    {
        return PluginUsuariosonlineConfig::podeVer();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    // =====================================================================
    // Sinal
    // =====================================================================

    /**
     * Registra o sinal da pessoa. $ocioso: segundos sem mexer na tela (informado pela aba).
     * O tempo desde o sinal anterior entra no histórico do dia (limitado a dois intervalos, para
     * que uma aba fechada sem aviso não conte horas a mais).
     */
    public static function sinal(int $usuario, string $estado, int $ocioso, int $perfil, ?int $agora = null): void
    {
        global $DB;
        if ($usuario <= 0) {
            return;
        }
        $agora ??= time();
        $estado = $estado === 'ausente' ? 'ausente' : 'ativo';
        $ocioso = max(0, min($ocioso, 86400));
        $agoraTxt = date('Y-m-d H:i:s', $agora);
        $C = PluginUsuariosonlineConfig::class;

        $anterior = null;
        foreach ($DB->request(['FROM' => self::SINAIS, 'WHERE' => ['users_id' => $usuario], 'LIMIT' => 1]) as $r) {
            $anterior = $r;
        }
        $ultimo = $anterior && $anterior['ultimo_sinal'] ? (int) strtotime((string) $anterior['ultimo_sinal']) : 0;
        $continua = $ultimo > 0 && ($agora - $ultimo) <= $C::offline();
        $delta = $continua ? max(0, min($agora - $ultimo, $C::intervalo() * 2 + 5)) : 0;
        // O tempo parado vem da aba e já considera todas as abas abertas da pessoa
        $atividade = date('Y-m-d H:i:s', $agora - $ocioso);

        $dados = [
            'profiles_id'      => max(0, $perfil),
            'estado'           => $estado,
            'ultima_atividade' => $atividade,
            'ultimo_sinal'     => $agoraTxt,
        ];
        if (!$continua) {
            $dados['online_desde'] = $agoraTxt;
        }
        if ($anterior) {
            $DB->update(self::SINAIS, $dados, ['users_id' => $usuario]);
        } else {
            $DB->insert(self::SINAIS, $dados + ['users_id' => $usuario, 'online_desde' => $agoraTxt]);
        }

        // Histórico do dia
        $dia = date('Y-m-d', $agora);
        $hoje = null;
        foreach ($DB->request(['FROM' => self::HISTORICO, 'WHERE' => ['users_id' => $usuario, 'data' => $dia], 'LIMIT' => 1]) as $r) {
            $hoje = $r;
        }
        // Virada do dia: o tempo antes da meia-noite fica no dia anterior
        if ($hoje === null && $continua && date('Y-m-d', $ultimo) !== $dia) {
            $delta = max(0, $agora - (int) strtotime($dia . ' 00:00:00'));
        }
        $ativo = $estado === 'ativo' ? $delta : 0;
        if ($hoje === null) {
            $DB->insert(self::HISTORICO, [
                'users_id'        => $usuario,
                'data'            => $dia,
                'primeiro_acesso' => $agoraTxt,
                'ultimo_acesso'   => $agoraTxt,
                'segundos_online' => $delta,
                'segundos_ativos' => $ativo,
            ]);
        } else {
            $DB->update(self::HISTORICO, [
                'ultimo_acesso'   => $agoraTxt,
                'segundos_online' => (int) $hoje['segundos_online'] + $delta,
                'segundos_ativos' => (int) $hoje['segundos_ativos'] + $ativo,
            ], ['id' => (int) $hoje['id']]);
        }
    }

    /** A aba avisa que está saindo (logout ou fechamento): a pessoa some na hora */
    public static function saiu(int $usuario): void
    {
        global $DB;
        $DB->update(self::SINAIS, ['ultimo_sinal' => date('Y-m-d H:i:s', time() - PluginUsuariosonlineConfig::offline() - 1)], ['users_id' => $usuario]);
    }

    // =====================================================================
    // Quem está online
    // =====================================================================

    /** Critério das pessoas online (respeita "quem aparece na lista") */
    private static function criterioOnline(): array
    {
        $where = [
            's.ultimo_sinal' => ['>=', date('Y-m-d H:i:s', time() - PluginUsuariosonlineConfig::offline())],
            'u.is_active'    => 1,
            'u.is_deleted'   => 0,
        ];
        $perfis = PluginUsuariosonlineConfig::ids('perfis_listar');
        if ($perfis) {
            $where['s.profiles_id'] = $perfis;
        }
        return [
            'FROM'       => self::SINAIS . ' AS s',
            'INNER JOIN' => ['glpi_users AS u' => ['ON' => ['s' => 'users_id', 'u' => 'id']]],
            'WHERE'      => $where,
        ];
    }

    public static function total(): array
    {
        global $DB;
        $r = ['total' => 0, 'ativos' => 0, 'ausentes' => 0];
        foreach ($DB->request(['SELECT' => ['s.estado', new QueryExpression('COUNT(*) AS n')], 'GROUPBY' => 's.estado'] + self::criterioOnline()) as $l) {
            $n = (int) $l['n'];
            $r['total'] += $n;
            $r[$l['estado'] === 'ausente' ? 'ausentes' : 'ativos'] += $n;
        }
        return $r;
    }

    /** Pessoas online agrupadas por grupo, com contadores de chamados e links para a busca nativa */
    public static function lista(): array
    {
        global $DB;
        $C = PluginUsuariosonlineConfig::class;
        $agora = time();
        $pessoas = [];
        $perfis = [];
        foreach ($DB->request([
            'SELECT' => ['s.users_id', 's.profiles_id', 's.estado', 's.online_desde', 's.ultima_atividade', 's.ultimo_sinal', 'u.name', 'u.firstname', 'u.realname', 'u.picture'],
        ] + self::criterioOnline()) as $r) {
            $uid = (int) $r['users_id'];
            $nome = trim(trim((string) $r['firstname']) . ' ' . trim((string) $r['realname']));
            $nome = $nome !== '' ? $nome : (string) $r['name'];
            $partes = preg_split('/\s+/', $nome) ?: [$nome];
            $iniciais = mb_strtoupper(mb_substr($partes[0], 0, 1) . (count($partes) > 1 ? mb_substr(end($partes), 0, 1) : ''));
            $desde = (int) strtotime((string) $r['online_desde']);
            $parado = max(0, $agora - (int) strtotime((string) $r['ultima_atividade']));
            $pessoas[$uid] = [
                'id'          => $uid,
                'nome'        => $nome,
                'login'       => (string) $r['name'],
                'iniciais'    => $iniciais,
                'foto'        => !empty($r['picture']) ? User::getThumbnailURLForPicture((string) $r['picture']) : '',
                'perfil_id'   => (int) $r['profiles_id'],
                'perfil'      => '',
                'estado'      => $r['estado'] === 'ausente' ? 'ausente' : 'ativo',
                'desde'       => $desde > 0 ? (date('Y-m-d', $desde) === date('Y-m-d', $agora) ? date('H:i', $desde) : date('d/m H:i', $desde)) : '',
                'desde_ts'    => $desde,
                'parado_seg'  => $parado,
                'atividade'   => $parado < 90 ? 'ativo agora' : 'sem atividade há ' . $C::duracao($parado),
                'grupos'      => [],
                'url'         => User::canView() ? User::getFormURLWithID($uid) : '',
            ];
            $perfis[(int) $r['profiles_id']] = true;
        }
        $nomesPerfis = [];
        if ($perfis) {
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'WHERE' => ['id' => array_keys($perfis)]]) as $p) {
                $nomesPerfis[(int) $p['id']] = (string) $p['name'];
            }
        }
        $grupos = [];
        if ($pessoas) {
            $filtro = $C::ids('grupos');
            foreach ($DB->request([
                'SELECT'     => ['gu.users_id', 'g.id', 'g.name', 'g.completename'],
                'FROM'       => 'glpi_groups_users AS gu',
                'INNER JOIN' => ['glpi_groups AS g' => ['ON' => ['gu' => 'groups_id', 'g' => 'id']]],
                'WHERE'      => ['gu.users_id' => array_keys($pessoas)] + ($filtro ? ['g.id' => $filtro] : []),
                'ORDER'      => 'g.completename ASC',
            ]) as $g) {
                $gid = (int) $g['id'];
                $grupos[$gid] ??= ['id' => $gid, 'nome' => (string) ($g['completename'] ?: $g['name']), 'pessoas' => []];
                $grupos[$gid]['pessoas'][] = (int) $g['users_id'];
                $pessoas[(int) $g['users_id']]['grupos'][] = $gid;
            }
        }
        $contadores = $C::getConfig('contadores') === '1' ? self::contadores(array_keys($pessoas)) : [];
        foreach ($pessoas as $uid => &$p) {
            $p['perfil'] = $nomesPerfis[$p['perfil_id']] ?? '';
            $p['grupos_nomes'] = array_map(fn($g) => $grupos[$g]['nome'], $p['grupos']);
            if ($contadores) {
                $p['chamados'] = $contadores[$uid] ?? ['novos' => 0, 'atendimento' => 0, 'pendentes' => 0, 'requerente' => 0];
                $p['links'] = self::links($uid, self::gruposDoUsuario($uid));
            }
        }
        unset($p);
        $semGrupo = array_values(array_filter(array_keys($pessoas), fn($uid) => !$pessoas[$uid]['grupos']));
        $lista = array_values($grupos);
        if ($semGrupo && $C::getConfig('sem_grupo') === '1') {
            $lista[] = ['id' => 0, 'nome' => $C::ids('grupos') ? 'Outros' : 'Sem grupo', 'pessoas' => $semGrupo];
        }
        $porNome = fn($a, $b) => strcasecmp($pessoas[$a]['nome'], $pessoas[$b]['nome']);
        foreach ($lista as &$g) {
            usort($g['pessoas'], $porNome);
        }
        unset($g);
        $ativos = count(array_filter($pessoas, fn($p) => $p['estado'] === 'ativo'));
        return [
            'total'      => count($pessoas),
            'ativos'     => $ativos,
            'ausentes'   => count($pessoas) - $ativos,
            'grupos'     => $lista,
            'pessoas'    => (object) $pessoas,
            'contadores' => (bool) $contadores,
        ];
    }

    private static function gruposDoUsuario(int $usuario): array
    {
        global $DB;
        static $cache = [];
        if (!isset($cache[$usuario])) {
            $cache[$usuario] = [];
            foreach ($DB->request(['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $usuario]]) as $r) {
                $cache[$usuario][] = (int) $r['groups_id'];
            }
        }
        return $cache[$usuario];
    }

    /**
     * Chamados de cada pessoa, dentro das entidades de quem está vendo:
     * novos em que é observadora (ela ou um grupo dela), em atendimento e pendentes atribuídos
     * a ela, e abertos em que é requerente. Poucas consultas para todas as pessoas juntas.
     */
    public static function contadores(array $usuarios): array
    {
        global $DB;
        $usuarios = array_values(array_filter(array_map('intval', $usuarios), fn($v) => $v > 0));
        $r = [];
        foreach ($usuarios as $u) {
            $r[$u] = ['novos' => 0, 'atendimento' => 0, 'pendentes' => 0, 'requerente' => 0];
        }
        if (!$usuarios) {
            return $r;
        }
        $entidades = getEntitiesRestrictCriteria('glpi_tickets', '', '', false);
        $base = fn(array $where) => [
            'FROM'       => 'glpi_tickets',
            'INNER JOIN' => ['glpi_tickets_users' => ['ON' => ['glpi_tickets' => 'id', 'glpi_tickets_users' => 'tickets_id']]],
            'WHERE'      => ['glpi_tickets.is_deleted' => 0] + $where + $entidades,
        ];

        foreach ($DB->request(['SELECT' => ['glpi_tickets_users.users_id', 'glpi_tickets.status', new QueryExpression('COUNT(DISTINCT glpi_tickets.id) AS n')], 'GROUPBY' => ['glpi_tickets_users.users_id', 'glpi_tickets.status']] + $base([
            'glpi_tickets_users.users_id' => $usuarios,
            'glpi_tickets_users.type'     => CommonITILActor::ASSIGN,
            'glpi_tickets.status'         => [Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING],
        ])) as $l) {
            $chave = (int) $l['status'] === Ticket::WAITING ? 'pendentes' : 'atendimento';
            $r[(int) $l['users_id']][$chave] += (int) $l['n'];
        }

        foreach ($DB->request(['SELECT' => ['glpi_tickets_users.users_id', new QueryExpression('COUNT(DISTINCT glpi_tickets.id) AS n')], 'GROUPBY' => 'glpi_tickets_users.users_id'] + $base([
            'glpi_tickets_users.users_id' => $usuarios,
            'glpi_tickets_users.type'     => CommonITILActor::REQUESTER,
            'glpi_tickets.status'         => Ticket::getNotSolvedStatusArray(),
        ])) as $l) {
            $r[(int) $l['users_id']]['requerente'] = (int) $l['n'];
        }

        // Novos como observador: direto ou por grupo
        $novos = [];
        foreach ($DB->request(['SELECT' => ['glpi_tickets_users.users_id', 'glpi_tickets.id']] + $base([
            'glpi_tickets_users.users_id' => $usuarios,
            'glpi_tickets_users.type'     => CommonITILActor::OBSERVER,
            'glpi_tickets.status'         => Ticket::INCOMING,
        ])) as $l) {
            $novos[(int) $l['users_id']][(int) $l['id']] = true;
        }
        $membros = [];
        foreach ($DB->request(['SELECT' => ['users_id', 'groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $usuarios]]) as $l) {
            $membros[(int) $l['groups_id']][] = (int) $l['users_id'];
        }
        if ($membros) {
            foreach ($DB->request([
                'SELECT'     => ['glpi_groups_tickets.groups_id', 'glpi_tickets.id'],
                'FROM'       => 'glpi_tickets',
                'INNER JOIN' => ['glpi_groups_tickets' => ['ON' => ['glpi_tickets' => 'id', 'glpi_groups_tickets' => 'tickets_id']]],
                'WHERE'      => [
                    'glpi_tickets.is_deleted'       => 0,
                    'glpi_groups_tickets.groups_id' => array_keys($membros),
                    'glpi_groups_tickets.type'      => CommonITILActor::OBSERVER,
                    'glpi_tickets.status'           => Ticket::INCOMING,
                ] + $entidades,
            ]) as $l) {
                foreach ($membros[(int) $l['groups_id']] ?? [] as $u) {
                    $novos[$u][(int) $l['id']] = true;
                }
            }
        }
        foreach ($novos as $u => $ids) {
            $r[$u]['novos'] = count($ids);
        }
        return $r;
    }

    /** Links para a busca nativa de chamados com os mesmos filtros dos contadores */
    public static function links(int $usuario, array $grupos): array
    {
        $url = Ticket::getSearchURL();
        $montar = fn(array $criterios) => $url . '?' . http_build_query(['criteria' => $criterios, 'reset' => 'reset']);
        $status = fn($valor) => ['link' => 'AND', 'field' => 12, 'searchtype' => 'equals', 'value' => $valor];
        $observador = [['link' => 'AND', 'field' => 66, 'searchtype' => 'equals', 'value' => $usuario]];
        foreach ($grupos as $g) {
            $observador[] = ['link' => 'OR', 'field' => 65, 'searchtype' => 'equals', 'value' => $g];
        }
        return [
            'novos'       => $montar([$status(Ticket::INCOMING), ['link' => 'AND', 'criteria' => $observador]]),
            'atendimento' => $montar([$status('process'), ['link' => 'AND', 'field' => 5, 'searchtype' => 'equals', 'value' => $usuario]]),
            'pendentes'   => $montar([$status(Ticket::WAITING), ['link' => 'AND', 'field' => 5, 'searchtype' => 'equals', 'value' => $usuario]]),
            'requerente'  => $montar([['link' => 'AND', 'field' => 4, 'searchtype' => 'equals', 'value' => $usuario], $status('notold')]),
        ];
    }

    // =====================================================================
    // Histórico
    // =====================================================================

    /** Resumo por pessoa no período (ou dia a dia de uma pessoa) */
    public static function historico(string $inicio, string $fim, int $grupo = 0, int $usuario = 0): array
    {
        global $DB;
        $where = ['h.data' => ['>=', $inicio], ['h.data' => ['<=', $fim]], 'u.is_deleted' => 0];
        if ($grupo > 0) {
            $where[] = ['h.users_id' => new QuerySubQuery(['SELECT' => 'users_id', 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $grupo]])];
        }
        if ($usuario > 0) {
            $where['h.users_id'] = $usuario;
        }
        $base = [
            'FROM'       => self::HISTORICO . ' AS h',
            'INNER JOIN' => ['glpi_users AS u' => ['ON' => ['h' => 'users_id', 'u' => 'id']]],
            'WHERE'      => $where,
        ];
        $linhas = [];
        if ($usuario > 0) {
            foreach ($DB->request(['SELECT' => ['h.*'], 'ORDER' => 'h.data DESC'] + $base) as $r) {
                $linhas[] = [
                    'data'     => (string) $r['data'],
                    'dias'     => 1,
                    'primeiro' => (string) $r['primeiro_acesso'],
                    'ultimo'   => (string) $r['ultimo_acesso'],
                    'ativos'   => (int) $r['segundos_ativos'],
                    'online'   => (int) $r['segundos_online'],
                ];
            }
            return $linhas;
        }
        foreach ($DB->request([
            'SELECT'  => [
                'h.users_id',
                new QueryExpression('COUNT(*) AS dias'),
                new QueryExpression('MIN(h.primeiro_acesso) AS primeiro'),
                new QueryExpression('MAX(h.ultimo_acesso) AS ultimo'),
                new QueryExpression('SUM(h.segundos_ativos) AS ativos'),
                new QueryExpression('SUM(h.segundos_online) AS online'),
            ],
            'GROUPBY' => 'h.users_id',
            'ORDER'   => 'ativos DESC',
        ] + $base) as $r) {
            $linhas[] = [
                'users_id' => (int) $r['users_id'],
                'nome'     => (string) getUserName((int) $r['users_id']),
                'dias'     => (int) $r['dias'],
                'primeiro' => (string) $r['primeiro'],
                'ultimo'   => (string) $r['ultimo'],
                'ativos'   => (int) $r['ativos'],
                'online'   => (int) $r['online'],
            ];
        }
        return $linhas;
    }

    // =====================================================================
    // Tarefa automática
    // =====================================================================

    public static function cronInfo($name)
    {
        return ['description' => 'Usuários Online: remove o histórico de presença mais antigo que o prazo de retenção'];
    }

    public static function cronUsuariosonlineLimpar($task = null)
    {
        global $DB;
        $dias = max(7, (int) PluginUsuariosonlineConfig::getConfig('retencao'));
        $DB->delete(self::HISTORICO, ['data' => ['<', date('Y-m-d', strtotime('-' . $dias . ' days'))]]);
        $removidas = (int) $DB->affectedRows();
        // Sinais de quem não aparece há mais de um dia
        $DB->delete(self::SINAIS, ['ultimo_sinal' => ['<', date('Y-m-d H:i:s', time() - 86400)]]);
        if ($task instanceof CronTask) {
            $task->addVolume($removidas);
        }
        return $removidas > 0 ? 1 : 0;
    }
}
