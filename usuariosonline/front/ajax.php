<?php

/**
 * Plugin Usuários Online - endpoint AJAX (sempre JSON).
 * sinal (POST): registra a presença de quem está logado e, para quem pode ver, devolve o
 * total e (com lista=1) as pessoas online. saiu (POST): a aba avisa que está saindo.
 * lista (GET): pessoas online, para o painel e a página.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno: ' . $erro['message']]);
    }
});

$C = PluginUsuariosonlineConfig::class;
$P = PluginUsuariosonlinePresenca::class;
$post = strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$responder = function (array $dados) use ($C, $post): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = $post ? $C::tokenCsrf() : '';
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$falhar = fn(string $mensagem, array $extra = []) => $responder(['success' => false, 'mensagem' => $mensagem] + $extra);

$usuario = (int) Session::getLoginUserID();
if ($usuario <= 0) {
    $falhar('Sessão expirada.', ['sessao' => false]);
}

try {
    switch ((string) ($_REQUEST['action'] ?? '')) {
        case 'sinal':
            if (!$post) {
                $falhar('Requisição inválida.');
            }
            $P::sinal($usuario, (string) ($_POST['estado'] ?? 'ativo'), (int) ($_POST['ocioso'] ?? 0), (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0));
            $ver = $C::podeVer();
            $dados = [
                'success' => true,
                'ver'     => $ver,
                'config'  => ['intervalo' => $C::intervalo(), 'ausente_min' => $C::ausenteMinutos(), 'usuario' => $usuario],
            ];
            if ($ver) {
                $dados['resumo'] = $P::total();
                $dados['pagina'] = $C::url('online.php');
                if (!empty($_POST['lista'])) {
                    $dados['lista'] = $P::lista();
                }
            }
            $responder($dados);

        case 'saiu':
            if (!$post) {
                $falhar('Requisição inválida.');
            }
            $P::saiu($usuario);
            $responder(['success' => true]);

        case 'lista':
            if (!$C::podeVer()) {
                $falhar('Sem permissão.', ['ver' => false]);
            }
            $responder(['success' => true, 'lista' => $P::lista()]);
    }
    $falhar('Ação desconhecida.');
} catch (\Throwable $e) {
    error_log('Plugin usuariosonline: ' . $e->getMessage());
    $falhar('Não foi possível concluir a operação.');
}
