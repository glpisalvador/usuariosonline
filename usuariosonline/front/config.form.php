<?php

/**
 * Plugin Usuários Online - configuração (marketplace e menu). Faz POST para esta mesma página.
 */

Session::checkLoginUser();

$C = PluginUsuariosonlineConfig::class;
$P = PluginUsuariosonlinePresenca::class;
$e = [$C, 'e'];

if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$ABAS = [
    'geral'    => ['ti ti-settings', 'Geral'],
    'situacao' => ['ti ti-chart-bar', 'Situação'],
];
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'geral');
if (!isset($ABAS[$aba])) {
    $aba = 'geral';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    switch ((string) $_POST['save_action']) {
        case 'salvar_geral':
            $C::setArrayConfig('perfis_ver', $C::idsPost('perfis_ver'));
            $C::setArrayConfig('perfis_listar', $C::idsPost('perfis_listar'));
            $C::setArrayConfig('grupos', $C::idsPost('grupos'));
            $C::setConfig('sem_grupo', empty($_POST['sem_grupo']) ? '0' : '1');
            $C::setConfig('contadores', empty($_POST['contadores']) ? '0' : '1');
            $C::setConfig('intervalo', (string) max(10, min(300, (int) ($_POST['intervalo'] ?? 30))));
            $C::setConfig('ausente_min', (string) max(1, min(120, (int) ($_POST['ausente_min'] ?? 5))));
            $C::setConfig('offline_seg', (string) max(30, min(3600, (int) ($_POST['offline_seg'] ?? 90))));
            $C::setConfig('retencao', (string) max(7, min(3650, (int) ($_POST['retencao'] ?? 180))));
            Session::addMessageAfterRedirect('Configuração salva.', false, INFO);
            break;

        case 'limpar_agora':
            $antes = countElementsInTable($P::HISTORICO);
            $P::cronUsuariosonlineLimpar();
            $n = $antes - countElementsInTable($P::HISTORICO);
            Session::addMessageAfterRedirect($n > 0 ? $n . ' registro(s) antigo(s) removido(s).' : 'Nenhum registro passou do prazo de retenção.', false, INFO);
            break;
    }
}

Html::header('Usuários Online', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginUsuariosonlineMenu');

$form = fn(string $acao, string $abaForm) => '<form method="post" action="' . $e($C::url('config.form.php')) . '" class="usuariosonline-form">'
    . '<input type="hidden" name="save_action" value="' . $e($acao) . '"><input type="hidden" name="aba" value="' . $e($abaForm) . '">';
$card = fn(string $icone, string $titulo, string $corpo) => '<div class="card usuariosonline-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body">' . $corpo . '</div></div>';
$campo = fn(string $rotulo, string $controle, string $dica = '') => '<div class="usuariosonline-campo"><label>' . $e($rotulo) . '</label>' . $controle . ($dica !== '' ? '<small>' . $dica . '</small>' : '') . '</div>';
$numero = fn(string $nome, int $valor, int $min, int $max) => '<input type="number" class="form-control form-control-sm usuariosonline-numero" name="' . $nome . '" min="' . $min . '" max="' . $max . '" value="' . $valor . '">';
$switch = fn(string $nome, string $rotulo, string $descricao, bool $ligado) => '<div class="form-check form-switch usuariosonline-switch usuariosonline-switch-linha"><input type="hidden" name="' . $nome . '" value="0">'
    . '<input class="form-check-input" type="checkbox" id="usuariosonline-cfg-' . $nome . '" name="' . $nome . '" value="1"' . ($ligado ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="usuariosonline-cfg-' . $nome . '"><span>' . $e($rotulo) . '</span><small>' . $e($descricao) . '</small></label></div>';
$explicacao = fn(string $texto) => '<p class="usuariosonline-explicacao"><i class="ti ti-info-circle"></i><span>' . $texto . '</span></p>';

echo '<div class="usuariosonline-pagina usuariosonline-config">';
echo '<ul class="nav nav-tabs usuariosonline-abas">';
foreach ($ABAS as $chave => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a class="nav-link' . ($aba === $chave ? ' active' : '') . '" href="#" data-aba="' . $chave . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ---------------------------------------------------------------- Geral
echo '<div data-aba-painel="geral"' . ($aba !== 'geral' ? ' hidden' : '') . '>' . $form('salvar_geral', 'geral');
echo '<div class="usuariosonline-grade">';
echo $card('ti ti-shield-lock', 'Acesso', $explicacao('Todo usuário logado registra presença; estas opções definem quem vê o painel e quem aparece nele.')
    . $campo('Perfis que veem o painel e a página', $C::multiselect('perfis_ver', $C::listarPerfis(), $C::ids('perfis_ver'), 'Quem tem direito de configuração'), 'Vazio: só quem tem direito de ver a configuração do GLPI.')
    . $campo('Perfis que aparecem na lista', $C::multiselect('perfis_listar', $C::listarPerfis(), $C::ids('perfis_listar'), 'Todos os perfis'), 'Considera o perfil em uso no momento. Vazio: todos aparecem.'));
echo $card('ti ti-layout-list', 'Exibição', $campo('Grupos exibidos', $C::multiselect('grupos', $C::listarGrupos(), $C::ids('grupos'), 'Todos os grupos'), 'Vazio: todos os grupos. Pessoas fora dos grupos escolhidos ficam em "Outros".')
    . $switch('sem_grupo', 'Mostrar pessoas sem grupo', 'Ficam no fim da lista, em "Sem grupo" (ou "Outros").', $C::getConfig('sem_grupo') === '1')
    . $switch('contadores', 'Mostrar contadores de chamados', 'Novos como observador, em atendimento, pendentes e como requerente, com link para a busca. Respeitam as entidades de quem está vendo.', $C::getConfig('contadores') === '1'));
echo $card('ti ti-clock', 'Tempos', '<div class="usuariosonline-grade-campos">'
    . $campo('Sinal e atualização (segundos)', $numero('intervalo', $C::intervalo(), 10, 300), 'Cada aba avisa que está online neste intervalo.')
    . $campo('Ausente após (minutos sem atividade)', $numero('ausente_min', $C::ausenteMinutos(), 1, 120), 'Sem mexer no mouse ou teclado em nenhuma aba do GLPI.')
    . $campo('Offline após (segundos sem sinal)', $numero('offline_seg', (int) $C::getConfig('offline_seg'), 30, 3600), 'Mínimo efetivo: duas vezes o intervalo + 10 s (hoje ' . (int) $C::offline() . ' s).')
    . $campo('Guardar histórico por (dias)', $numero('retencao', (int) $C::getConfig('retencao'), 7, 3650), 'A tarefa automática "UsuariosonlineLimpar" remove o mais antigo uma vez por dia.')
    . '</div>');
echo '</div>';
echo '<div class="usuariosonline-rodape-form"><button type="submit" class="btn btn-sm usuariosonline-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar</span></button></div>';
echo Html::closeForm(false) . '</div>';

// ---------------------------------------------------------------- Situação
echo '<div data-aba-painel="situacao"' . ($aba !== 'situacao' ? ' hidden' : '') . '>';
$agora = $P::total();
$cron = new CronTask();
$temCron = $cron->getFromDBbyName('PluginUsuariosonlinePresenca', 'UsuariosonlineLimpar');
$corpo = '<div class="usuariosonline-numeros">'
    . '<div><strong>' . (int) $agora['total'] . '</strong><span>online agora (' . (int) $agora['ativos'] . ' ativos, ' . (int) $agora['ausentes'] . ' ausentes)</span></div>'
    . '<div><strong>' . (int) countElementsInTable($P::HISTORICO, ['data' => date('Y-m-d')]) . '</strong><span>pessoas acessaram hoje</span></div>'
    . '<div><strong>' . (int) countElementsInTable($P::HISTORICO) . '</strong><span>registros diários guardados</span></div>'
    . '</div>'
    . '<div class="usuariosonline-linha-acao"><span class="usuariosonline-pequeno"><i class="ti ti-clock"></i> Limpeza automática: '
    . ($temCron ? ($cron->fields['lastrun'] ? 'última execução em ' . $e(Html::convDateTime((string) $cron->fields['lastrun'])) : 'ainda não executada') . ' · <a href="' . $e(CronTask::getFormURLWithID((int) $cron->getID())) . '">ver tarefa</a>' : 'tarefa não registrada (reinstale o plugin)')
    . '</span>' . $form('limpar_agora', 'situacao') . '<button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-eraser"></i><span>Limpar agora</span></button>' . Html::closeForm(false) . '</div>';
echo $card('ti ti-chart-bar', 'Situação', $corpo) . '</div>';

echo '</div>';
Html::footer();
