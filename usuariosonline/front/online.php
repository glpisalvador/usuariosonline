<?php

/**
 * Plugin Usuários Online - página (Ferramentas > Usuários online): quem está online agora e
 * histórico de presença por período.
 */

Session::checkLoginUser();

$C = PluginUsuariosonlineConfig::class;
$P = PluginUsuariosonlinePresenca::class;
$e = [$C, 'e'];

if (!$C::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$aba = (string) ($_GET['aba'] ?? 'agora');
$aba = in_array($aba, ['agora', 'historico'], true) ? $aba : 'agora';
$valida = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
$fim = $valida($_GET['fim'] ?? null) ?? date('Y-m-d');
$inicio = $valida($_GET['inicio'] ?? null) ?? date('Y-m-d', strtotime('-6 days'));
if ($inicio > $fim) {
    [$inicio, $fim] = [$fim, $inicio];
}
$grupo = (int) ($_GET['grupo'] ?? 0);
$pessoa = (int) ($_GET['usuario'] ?? 0);

Html::header('Usuários online', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginUsuariosonlineMenu');

echo '<div class="usuariosonline-pagina" data-usuariosonline-pagina data-ajax="' . $e($C::url('ajax.php')) . '" data-intervalo="' . (int) $C::intervalo() . '">';
echo '<ul class="nav nav-tabs usuariosonline-abas">'
    . '<li class="nav-item"><a class="nav-link' . ($aba === 'agora' ? ' active' : '') . '" href="#" data-aba="agora"><i class="ti ti-users"></i> Agora <span class="usuariosonline-contador" data-usuariosonline-total></span></a></li>'
    . '<li class="nav-item"><a class="nav-link' . ($aba === 'historico' ? ' active' : '') . '" href="#" data-aba="historico"><i class="ti ti-history"></i> Histórico</a></li>'
    . '</ul>';

// ---------------------------------------------------------------- Agora (preenchido e atualizado pelo JS)
$opcoesGrupo = '<option value="">Todos os grupos</option>';
foreach ($C::listarGrupos() as $id => $nome) {
    if (!$C::ids('grupos') || in_array($id, $C::ids('grupos'), true)) {
        $opcoesGrupo .= '<option value="' . (int) $id . '"' . ($id === $grupo ? ' selected' : '') . '>' . $e($nome) . '</option>';
    }
}
echo '<div data-aba-painel="agora"' . ($aba !== 'agora' ? ' hidden' : '') . '>';
echo '<div class="card usuariosonline-card"><div class="card-header usuariosonline-barra">'
    . '<div class="usuariosonline-resumo" data-usuariosonline-resumo></div>'
    . '<div class="usuariosonline-filtros">'
    . '<div class="usuariosonline-busca"><i class="ti ti-search"></i><input type="search" class="form-control form-control-sm" placeholder="Pesquisar pessoa, perfil ou grupo..." data-usuariosonline-busca></div>'
    . '<select class="form-select form-select-sm" data-usuariosonline-grupo>' . $opcoesGrupo . '</select>'
    . '<label class="usuariosonline-opcao"><input type="checkbox" class="usuariosonline-check" data-usuariosonline-so-ativos> Só ativos</label>'
    . '</div></div><div class="card-body p-0">'
    . '<div class="table-responsive"><table class="table table-sm table-hover usuariosonline-tabela" data-usuariosonline-tabela><thead><tr>'
    . '<th data-sort="nome">Pessoa</th><th data-sort="estado">Situação</th><th data-sort="perfil">Perfil</th><th data-sort="grupos">Grupos</th>'
    . '<th data-sort="desde_ts">Online desde</th><th data-sort="parado_seg">Última atividade</th>'
    . '<th class="text-center" data-sort="novos" title="Chamados novos em que é observador (ou um grupo dele)">Novos</th>'
    . '<th class="text-center" data-sort="atendimento" title="Chamados em atendimento atribuídos">Atendimento</th>'
    . '<th class="text-center" data-sort="pendentes" title="Chamados pendentes atribuídos">Pendentes</th>'
    . '<th class="text-center" data-sort="requerente" title="Chamados abertos em que é requerente">Requerente</th>'
    . '</tr></thead><tbody><tr><td colspan="10" class="usuariosonline-vazio"><i class="ti ti-loader-2"></i> Carregando...</td></tr></tbody></table></div>'
    . '</div><div class="card-footer usuariosonline-rodape"><span class="usuariosonline-pequeno"><i class="ti ti-refresh"></i> Atualiza a cada ' . (int) $C::intervalo() . ' s · ausente após ' . (int) $C::ausenteMinutos() . ' min sem atividade · offline após ' . (int) $C::offline() . ' s sem sinal</span>'
    . '<span class="usuariosonline-pequeno" data-usuariosonline-atualizado></span></div></div>';
echo '</div>';

// ---------------------------------------------------------------- Histórico
echo '<div data-aba-painel="historico"' . ($aba !== 'historico' ? ' hidden' : '') . '>';
echo '<div class="card usuariosonline-card"><div class="card-header usuariosonline-barra">';
echo '<form method="get" action="' . $e($C::url('online.php')) . '" class="usuariosonline-filtros">'
    . '<input type="hidden" name="aba" value="historico">' . ($pessoa > 0 ? '<input type="hidden" name="usuario" value="' . $pessoa . '">' : '')
    . '<label class="usuariosonline-rotulo">De</label>';
Html::showDateField('inicio', ['value' => $inicio, 'maybeempty' => false]);
echo '<label class="usuariosonline-rotulo">até</label>';
Html::showDateField('fim', ['value' => $fim, 'maybeempty' => false]);
echo '<select name="grupo" class="form-select form-select-sm">' . $opcoesGrupo . '</select>'
    . '<button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-filter"></i><span>Filtrar</span></button>'
    . '</form>'
    . '<div class="usuariosonline-atalhos">';
foreach ([0 => 'Hoje', 6 => '7 dias', 29 => '30 dias'] as $dias => $rotulo) {
    echo '<a class="btn btn-sm btn-ghost-secondary" href="' . $e($C::url('online.php', ['aba' => 'historico', 'inicio' => date('Y-m-d', strtotime('-' . $dias . ' days')), 'fim' => date('Y-m-d'), 'grupo' => $grupo ?: null, 'usuario' => $pessoa ?: null])) . '">' . $e($rotulo) . '</a>';
}
echo '<button type="button" class="btn btn-sm btn-ghost-secondary" data-usuariosonline-csv="usuariosonline-historico-' . $e($inicio . '-' . $fim) . '.csv"><i class="ti ti-file-spreadsheet"></i><span>CSV</span></button></div>';
echo '</div><div class="card-body p-0">';

$linhas = $P::historico($inicio, $fim, $grupo, $pessoa);
$dt = fn(string $v) => $v !== '' ? Html::convDateTime($v) : '—';
if ($pessoa > 0) {
    echo '<div class="usuariosonline-titulo-detalhe"><a class="btn btn-sm btn-ghost-secondary" href="' . $e($C::url('online.php', ['aba' => 'historico', 'inicio' => $inicio, 'fim' => $fim, 'grupo' => $grupo ?: null])) . '"><i class="ti ti-arrow-left"></i><span>Todas as pessoas</span></a>'
        . '<strong>' . $e(getUserName($pessoa)) . '</strong><span class="usuariosonline-pequeno">dia a dia</span></div>';
}
if (!$linhas) {
    echo '<div class="usuariosonline-vazio"><i class="ti ti-mood-empty"></i> Nenhum registro de presença no período.</div>';
} else {
    $totAtivo = array_sum(array_column($linhas, 'ativos'));
    $totOnline = array_sum(array_column($linhas, 'online'));
    echo '<div class="table-responsive"><table class="table table-sm table-hover usuariosonline-tabela" data-usuariosonline-historico><thead><tr>'
        . ($pessoa > 0 ? '<th>Dia</th>' : '<th>Pessoa</th><th class="text-center">Dias</th>')
        . '<th>Primeiro acesso</th><th>Último acesso</th><th class="text-end">Tempo ativo</th><th class="text-end">Tempo online</th><th class="text-end">Ativo / online</th>'
        . ($pessoa > 0 ? '' : '<th class="text-end">Média ativa por dia</th>') . '</tr></thead><tbody>';
    foreach ($linhas as $l) {
        $pct = $l['online'] > 0 ? (int) round($l['ativos'] * 100 / $l['online']) : 0;
        echo '<tr>'
            . ($pessoa > 0
                ? '<td>' . $e(Html::convDate($l['data'])) . '</td>'
                : '<td><a href="' . $e($C::url('online.php', ['aba' => 'historico', 'inicio' => $inicio, 'fim' => $fim, 'grupo' => $grupo ?: null, 'usuario' => $l['users_id']])) . '">' . $e($l['nome']) . '</a></td><td class="text-center">' . (int) $l['dias'] . '</td>')
            . '<td>' . $e($dt($l['primeiro'])) . '</td><td>' . $e($dt($l['ultimo'])) . '</td>'
            . '<td class="text-end">' . $e($C::duracao($l['ativos'])) . '</td><td class="text-end">' . $e($C::duracao($l['online'])) . '</td>'
            . '<td class="text-end"><span class="usuariosonline-barra-pct" title="' . $pct . '%"><span style="width:' . $pct . '%"></span></span> ' . $pct . '%</td>'
            . ($pessoa > 0 ? '' : '<td class="text-end">' . $e($C::duracao(intdiv($l['ativos'], max(1, $l['dias'])))) . '</td>')
            . '</tr>';
    }
    echo '</tbody><tfoot><tr><th' . ($pessoa > 0 ? '' : ' colspan="2"') . '>Total</th><th></th><th></th><th class="text-end">' . $e($C::duracao($totAtivo)) . '</th><th class="text-end">' . $e($C::duracao($totOnline)) . '</th><th></th>' . ($pessoa > 0 ? '' : '<th></th>') . '</tr></tfoot></table></div>';
}
echo '</div><div class="card-footer usuariosonline-rodape"><span class="usuariosonline-pequeno"><i class="ti ti-info-circle"></i> Tempo online: com o GLPI aberto. Tempo ativo: mexendo na tela (sem ficar mais de ' . (int) $C::ausenteMinutos() . ' min parado). Registros guardados por ' . (int) $C::getConfig('retencao') . ' dias.</span></div></div>';
echo '</div>';

echo '</div>';
Html::footer();
