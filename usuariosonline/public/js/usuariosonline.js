/* Plugin Usuários Online - sinal de presença (todas as abas, sem repetir), painel na barra
 * superior, página "Agora" com tabela ordenável, exportação CSV do histórico, abas e multiselect. */
(function () {
    'use strict';

    if (window.usuariosonlineCarregado) {
        return;
    }
    window.usuariosonlineCarregado = true;

    var origem = (document.currentScript && document.currentScript.src) || '';
    var base = origem.split('?')[0].replace(/js\/usuariosonline\.js$/, '');
    if (!base) {
        return;
    }
    var AJAX = base + 'front/ajax.php';
    var CHAVE_ATIVIDADE = 'usuariosonline-atividade';
    var CHAVE_SINAL = 'usuariosonline-sinal';
    var CHAVE_RESUMO = 'usuariosonline-resumo';
    var CHAVE_FECHADOS = 'usuariosonline-grupos-fechados';

    var estado = {
        config: { intervalo: 30, ausente_min: 5 },
        ver: false,
        pagina: '',
        lista: null,
        aberto: false,
        timer: null,
        parado: false,
        atividade: Date.now(),
        ordem: { campo: 'nome', direcao: 1 }
    };

    // ------------------------------------------------------------------ utilitários

    var esc = function (t) {
        return String(t === null || t === undefined ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    var lerJson = function (texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try {
                    return JSON.parse(m[0]);
                } catch (e2) { /* segue */ }
            }
        }
        return { success: false };
    };

    var ler = function (chave) {
        try {
            return window.localStorage.getItem(chave);
        } catch (e) {
            return null;
        }
    };

    var gravar = function (chave, valor) {
        try {
            window.localStorage.setItem(chave, valor);
        } catch (e) { /* armazenamento indisponível */ }
    };

    var token = function () {
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.getAttribute('content') : '';
    };

    var pedir = function (acao, dados, metodo, manter) {
        var opcoes = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' };
        var url = AJAX + '?action=' + encodeURIComponent(acao);
        if (metodo === 'POST') {
            var fd = new FormData();
            fd.append('action', acao);
            Object.keys(dados || {}).forEach(function (k) { fd.append(k, dados[k]); });
            var t = token();
            if (t) {
                fd.append('_glpi_csrf_token', t);
                opcoes.headers['X-Glpi-Csrf-Token'] = t;
            }
            opcoes.method = 'POST';
            opcoes.body = fd;
            opcoes.keepalive = !!manter;
        }
        return fetch(url, opcoes).then(function (r) { return r.text(); }).then(function (texto) {
            var r = lerJson(texto);
            if (r.new_token) {
                var m = document.querySelector('meta[property="glpi:csrf_token"]');
                if (m) {
                    m.setAttribute('content', r.new_token);
                }
            }
            return r;
        });
    };

    // ------------------------------------------------------------------ atividade (compartilhada entre as abas)

    var ultimaGravacao = 0;
    var registrarAtividade = function () {
        var agora = Date.now();
        estado.atividade = agora;
        if (agora - ultimaGravacao > 10000) {
            ultimaGravacao = agora;
            gravar(CHAVE_ATIVIDADE, String(agora));
        }
    };
    ['mousemove', 'keydown', 'mousedown', 'scroll', 'touchstart', 'focus'].forEach(function (ev) {
        window.addEventListener(ev, registrarAtividade, { passive: true, capture: true });
    });
    registrarAtividade();

    var segundosParado = function () {
        var outra = parseInt(ler(CHAVE_ATIVIDADE) || '0', 10) || 0;
        return Math.max(0, Math.round((Date.now() - Math.max(estado.atividade, outra)) / 1000));
    };

    var estadoAtual = function () {
        return segundosParado() > estado.config.ausente_min * 60 ? 'ausente' : 'ativo';
    };

    // ------------------------------------------------------------------ sinal

    var precisaLista = function () {
        return estado.aberto || !!document.querySelector('[data-usuariosonline-pagina]');
    };

    var agendar = function () {
        clearTimeout(estado.timer);
        if (!estado.parado) {
            estado.timer = setTimeout(sinal, estado.config.intervalo * 1000);
        }
    };

    var aplicar = function (r) {
        estado.ver = !!r.ver;
        if (r.pagina) {
            estado.pagina = r.pagina;
        }
        if (estado.ver) {
            montarWidget();
            if (r.resumo) {
                contador(r.resumo);
            }
        } else if (widget) {
            widget.hidden = true;
        }
        if (r.lista) {
            estado.lista = r.lista;
            contador(r.lista);
            renderPainel();
            renderPagina();
        }
    };

    var sinal = function (forcar) {
        // Outra aba acabou de mandar o sinal: aproveita o resultado dela
        var ultimo = parseInt(ler(CHAVE_SINAL) || '0', 10) || 0;
        if (!forcar && !precisaLista() && estado.config.usuario && Date.now() - ultimo < estado.config.intervalo * 600) {
            var guardado = lerJson(ler(CHAVE_RESUMO) || '{}');
            if (guardado && guardado.success) {
                aplicar(guardado);
            }
            agendar();
            return;
        }
        gravar(CHAVE_SINAL, String(Date.now()));
        pedir('sinal', { estado: estadoAtual(), ocioso: segundosParado(), lista: precisaLista() ? 1 : 0 }, 'POST').then(function (r) {
            if (!r.success) {
                if (r.sessao === false) {
                    estado.parado = true;
                }
                agendar();
                return;
            }
            estado.config = r.config || estado.config;
            gravar(CHAVE_RESUMO, JSON.stringify({ success: true, ver: r.ver, resumo: r.resumo, pagina: r.pagina }));
            aplicar(r);
            agendar();
        }).catch(function () { agendar(); });
    };

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && !estado.parado) {
            registrarAtividade();
            sinal(true);
        }
    });

    // Saída pelo menu do usuário: some da lista na hora
    document.addEventListener('click', function (e) {
        var sair = e.target.closest('a[href*="logout.php"]');
        if (sair) {
            pedir('saiu', {}, 'POST', true);
        }
    }, true);

    // ------------------------------------------------------------------ widget na barra superior

    var widget = null;

    var montarWidget = function () {
        if (widget) {
            widget.hidden = false;
            return;
        }
        var cont = document.querySelector('header .header-container') || document.querySelector('header.navbar .container-fluid');
        if (!cont) {
            return;
        }
        widget = document.createElement('div');
        widget.className = 'usuariosonline-widget';
        widget.innerHTML =
            '<button type="button" class="usuariosonline-botao" aria-haspopup="dialog" aria-expanded="false" title="Usuários online">' +
                '<i class="ti ti-users"></i><span class="usuariosonline-numero-badge">0</span></button>' +
            '<div class="usuariosonline-painel" role="dialog" aria-label="Usuários online" hidden>' +
                '<div class="usuariosonline-painel-topo"><span class="usuariosonline-painel-titulo">Usuários online</span>' +
                    '<span class="usuariosonline-painel-resumo" data-resumo></span>' +
                    '<a class="btn btn-sm btn-ghost-secondary" data-link-pagina title="Abrir a página"><i class="ti ti-external-link"></i></a></div>' +
                '<div class="usuariosonline-painel-filtros">' +
                    '<div class="usuariosonline-busca"><i class="ti ti-search"></i><input type="search" class="form-control form-control-sm" placeholder="Pesquisar..." data-painel-busca></div>' +
                    '<select class="form-select form-select-sm" data-painel-grupo><option value="">Todos os grupos</option></select>' +
                '</div>' +
                '<div class="usuariosonline-painel-lista" data-painel-lista><div class="usuariosonline-vazio"><i class="ti ti-loader-2"></i> Carregando...</div></div>' +
            '</div>';
        var alvo = cont.querySelector(':scope > .notificacoes-sino') || Array.from(cont.children).filter(function (el) {
            return el.classList.contains('ms-md-4') || el.querySelector('.user-menu');
        }).pop();
        if (alvo) {
            cont.insertBefore(widget, alvo);
        } else {
            cont.appendChild(widget);
        }
        widget.querySelector('.usuariosonline-botao').addEventListener('click', function (e) {
            e.stopPropagation();
            abrirPainel(!estado.aberto);
        });
        widget.querySelector('[data-painel-busca]').addEventListener('input', renderPainel);
        widget.querySelector('[data-painel-grupo]').addEventListener('change', renderPainel);
        document.addEventListener('click', function (e) {
            if (estado.aberto && !widget.contains(e.target)) {
                abrirPainel(false);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && estado.aberto) {
                abrirPainel(false);
            }
        });
    };

    var abrirPainel = function (abrir) {
        estado.aberto = abrir;
        widget.querySelector('.usuariosonline-painel').hidden = !abrir;
        widget.querySelector('.usuariosonline-botao').setAttribute('aria-expanded', abrir ? 'true' : 'false');
        widget.classList.toggle('aberto', abrir);
        if (abrir) {
            widget.querySelector('[data-link-pagina]').href = estado.pagina || '#';
            renderPainel();
            sinal(true);
        }
    };

    var contador = function (r) {
        var texto = r.total + ' online · ' + r.ativos + ' ativo(s), ' + r.ausentes + ' ausente(s)';
        if (widget) {
            widget.querySelector('.usuariosonline-numero-badge').textContent = String(r.total || 0);
            widget.querySelector('.usuariosonline-botao').title = texto;
            widget.querySelector('[data-resumo]').textContent = r.ativos + ' ativo(s) · ' + r.ausentes + ' ausente(s)';
        }
        document.querySelectorAll('[data-usuariosonline-total]').forEach(function (el) { el.textContent = String(r.total || 0); });
    };

    var avatar = function (p) {
        return '<span class="usuariosonline-avatar">' + (p.foto ? '<img src="' + esc(p.foto) + '" alt="">' : esc(p.iniciais)) +
            '<span class="usuariosonline-status usuariosonline-status-' + esc(p.estado) + '" title="' + (p.estado === 'ativo' ? 'Ativo' : 'Ausente') + '"></span></span>';
    };

    var CONTADORES = [
        ['novos', 'ti ti-bell', 'Novos em que é observador'],
        ['atendimento', 'ti ti-tool', 'Em atendimento (atribuídos)'],
        ['pendentes', 'ti ti-player-pause', 'Pendentes (atribuídos)'],
        ['requerente', 'ti ti-user', 'Abertos como requerente']
    ];

    var pilulas = function (p, comRotulo) {
        if (!p.chamados) {
            return '';
        }
        return CONTADORES.map(function (c) {
            var n = p.chamados[c[0]] || 0;
            var conteudo = '<i class="' + c[1] + '"></i>' + n;
            return n > 0
                ? '<a class="usuariosonline-pilula usuariosonline-pilula-' + c[0] + '" href="' + esc(p.links[c[0]]) + '" title="' + esc(c[2]) + '">' + conteudo + '</a>'
                : '<span class="usuariosonline-pilula vazia" title="' + esc(c[2]) + '">' + conteudo + '</span>';
        }).join('');
    };

    var fechados = function () {
        return (ler(CHAVE_FECHADOS) || '').split(',').filter(Boolean);
    };

    var preencherGrupos = function (sel, lista) {
        var atual = sel.value;
        sel.innerHTML = '<option value="">Todos os grupos</option>' + lista.grupos.map(function (g) {
            return '<option value="' + g.id + '">' + esc(g.nome) + ' (' + g.pessoas.length + ')</option>';
        }).join('');
        sel.value = atual;
        if (sel.value !== atual) {
            sel.value = '';
        }
    };

    var bate = function (p, termo) {
        return !termo || (p.nome + ' ' + p.login + ' ' + p.perfil + ' ' + (p.grupos_nomes || []).join(' ')).toLowerCase().indexOf(termo) >= 0;
    };

    var renderPainel = function () {
        if (!widget || !estado.aberto || !estado.lista) {
            return;
        }
        var lista = estado.lista;
        var caixa = widget.querySelector('[data-painel-lista]');
        var sel = widget.querySelector('[data-painel-grupo]');
        preencherGrupos(sel, lista);
        var termo = widget.querySelector('[data-painel-busca]').value.trim().toLowerCase();
        var fechadosLista = fechados();
        var html = '';
        lista.grupos.forEach(function (g) {
            if (sel.value !== '' && String(g.id) !== sel.value) {
                return;
            }
            var pessoas = g.pessoas.map(function (id) { return lista.pessoas[id]; }).filter(function (p) { return p && bate(p, termo); });
            if (!pessoas.length) {
                return;
            }
            var fechado = !termo && fechadosLista.indexOf(String(g.id)) >= 0;
            html += '<div class="usuariosonline-grupo' + (fechado ? ' fechado' : '') + '" data-grupo="' + g.id + '">' +
                '<button type="button" class="usuariosonline-grupo-titulo" data-alternar-grupo="' + g.id + '"><i class="ti ti-chevron-down"></i><span>' + esc(g.nome) + '</span><span class="usuariosonline-grupo-n">' + pessoas.length + '</span></button>' +
                '<ul class="usuariosonline-pessoas">' + pessoas.map(function (p) {
                    var nome = p.url ? '<a href="' + esc(p.url) + '">' + esc(p.nome) + '</a>' : esc(p.nome);
                    return '<li class="usuariosonline-pessoa">' + avatar(p) +
                        '<span class="usuariosonline-pessoa-dados"><span class="usuariosonline-pessoa-nome">' + nome + (p.perfil ? ' <small>' + esc(p.perfil) + '</small>' : '') + '</span>' +
                        '<span class="usuariosonline-pessoa-meta">' + esc(p.atividade) + (p.desde ? ' · online desde ' + esc(p.desde) : '') + '</span></span>' +
                        '<span class="usuariosonline-pilulas">' + pilulas(p) + '</span></li>';
                }).join('') + '</ul></div>';
        });
        caixa.innerHTML = html || '<div class="usuariosonline-vazio"><i class="ti ti-mood-empty"></i> ' + (lista.total ? 'Ninguém encontrado com esse filtro.' : 'Ninguém online no momento.') + '</div>';
    };

    document.addEventListener('click', function (e) {
        var botao = e.target.closest('[data-alternar-grupo]');
        if (!botao) {
            return;
        }
        e.stopPropagation();
        var id = botao.dataset.alternarGrupo;
        var lista = fechados();
        var i = lista.indexOf(id);
        if (i >= 0) {
            lista.splice(i, 1);
        } else {
            lista.push(id);
        }
        gravar(CHAVE_FECHADOS, lista.join(','));
        botao.closest('.usuariosonline-grupo').classList.toggle('fechado', i < 0);
    });

    // ------------------------------------------------------------------ página "Agora"

    var renderPagina = function () {
        var raiz = document.querySelector('[data-usuariosonline-pagina]');
        if (!raiz || !estado.lista) {
            return;
        }
        var lista = estado.lista;
        var sel = raiz.querySelector('[data-usuariosonline-grupo]');
        var termo = raiz.querySelector('[data-usuariosonline-busca]').value.trim().toLowerCase();
        var soAtivos = raiz.querySelector('[data-usuariosonline-so-ativos]').checked;
        var noGrupo = null;
        if (sel.value !== '') {
            var g = lista.grupos.filter(function (x) { return String(x.id) === sel.value; })[0];
            noGrupo = g ? g.pessoas.map(String) : [];
        }
        var pessoas = Object.keys(lista.pessoas).map(function (id) { return lista.pessoas[id]; }).filter(function (p) {
            return bate(p, termo) && (!soAtivos || p.estado === 'ativo') && (!noGrupo || noGrupo.indexOf(String(p.id)) >= 0);
        });
        var o = estado.ordem;
        var valor = function (p) {
            if (['novos', 'atendimento', 'pendentes', 'requerente'].indexOf(o.campo) >= 0) {
                return p.chamados ? p.chamados[o.campo] : 0;
            }
            if (o.campo === 'grupos') {
                return (p.grupos_nomes || []).join(', ').toLowerCase();
            }
            var v = p[o.campo];
            return typeof v === 'string' ? v.toLowerCase() : v;
        };
        pessoas.sort(function (a, b) {
            var va = valor(a);
            var vb = valor(b);
            return (va < vb ? -1 : va > vb ? 1 : a.nome.localeCompare(b.nome, 'pt-BR')) * o.direcao;
        });
        raiz.querySelectorAll('th[data-sort]').forEach(function (th) {
            th.classList.toggle('sorted-asc', th.dataset.sort === o.campo && o.direcao === 1);
            th.classList.toggle('sorted-desc', th.dataset.sort === o.campo && o.direcao === -1);
        });
        var celula = function (p, c) {
            if (!p.chamados) {
                return '<td class="text-center usuariosonline-pequeno">—</td>';
            }
            var n = p.chamados[c] || 0;
            return '<td class="text-center">' + (n > 0 ? '<a class="usuariosonline-pilula usuariosonline-pilula-' + c + '" href="' + esc(p.links[c]) + '">' + n + '</a>' : '<span class="usuariosonline-pilula vazia">0</span>') + '</td>';
        };
        raiz.querySelector('[data-usuariosonline-tabela] tbody').innerHTML = pessoas.length ? pessoas.map(function (p) {
            return '<tr><td><span class="usuariosonline-pessoa-celula">' + avatar(p) + '<span>' + (p.url ? '<a href="' + esc(p.url) + '">' + esc(p.nome) + '</a>' : esc(p.nome)) + '<small>' + esc(p.login) + '</small></span></span></td>' +
                '<td><span class="usuariosonline-selo usuariosonline-selo-' + esc(p.estado) + '">' + (p.estado === 'ativo' ? 'Ativo' : 'Ausente') + '</span></td>' +
                '<td>' + esc(p.perfil) + '</td><td class="usuariosonline-pequeno">' + esc((p.grupos_nomes || []).join(', ') || '—') + '</td>' +
                '<td>' + esc(p.desde) + '</td><td>' + esc(p.atividade) + '</td>' +
                celula(p, 'novos') + celula(p, 'atendimento') + celula(p, 'pendentes') + celula(p, 'requerente') + '</tr>';
        }).join('') : '<tr><td colspan="10" class="usuariosonline-vazio"><i class="ti ti-mood-empty"></i> ' + (lista.total ? 'Ninguém encontrado com esses filtros.' : 'Ninguém online no momento.') + '</td></tr>';
        raiz.querySelector('[data-usuariosonline-resumo]').innerHTML =
            '<span class="usuariosonline-selo usuariosonline-selo-ativo"><i class="ti ti-point-filled"></i> ' + lista.ativos + ' ativo(s)</span>' +
            '<span class="usuariosonline-selo usuariosonline-selo-ausente"><i class="ti ti-point-filled"></i> ' + lista.ausentes + ' ausente(s)</span>' +
            '<span class="usuariosonline-pequeno">' + pessoas.length + ' de ' + lista.total + ' exibido(s)</span>';
        raiz.querySelector('[data-usuariosonline-atualizado]').textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR');
    };

    var iniciarPagina = function () {
        var raiz = document.querySelector('[data-usuariosonline-pagina]');
        if (!raiz) {
            return;
        }
        raiz.querySelector('[data-usuariosonline-busca]').addEventListener('input', renderPagina);
        raiz.querySelector('[data-usuariosonline-grupo]').addEventListener('change', renderPagina);
        raiz.querySelector('[data-usuariosonline-so-ativos]').addEventListener('change', renderPagina);
        raiz.querySelectorAll('th[data-sort]').forEach(function (th) {
            th.addEventListener('click', function () {
                estado.ordem = { campo: th.dataset.sort, direcao: estado.ordem.campo === th.dataset.sort ? -estado.ordem.direcao : 1 };
                renderPagina();
            });
        });
    };

    // ------------------------------------------------------------------ CSV do histórico

    document.addEventListener('click', function (e) {
        var botao = e.target.closest('[data-usuariosonline-csv]');
        if (!botao) {
            return;
        }
        var tabela = document.querySelector('[data-usuariosonline-historico]');
        if (!tabela) {
            return;
        }
        var linhas = Array.from(tabela.querySelectorAll('tr')).map(function (tr) {
            return Array.from(tr.children).map(function (c) {
                return '"' + c.textContent.trim().replace(/"/g, '""') + '"';
            }).join(';');
        });
        var blob = new Blob(['\uFEFF' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = botao.dataset.usuariosonlineCsv;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    });

    // ------------------------------------------------------------------ abas e multiselect (páginas do plugin)

    document.addEventListener('click', function (e) {
        var aba = e.target.closest('.usuariosonline-abas [data-aba]');
        if (!aba) {
            return;
        }
        e.preventDefault();
        var pagina = aba.closest('.usuariosonline-pagina');
        pagina.querySelectorAll('.usuariosonline-abas [data-aba]').forEach(function (l) { l.classList.toggle('active', l === aba); });
        pagina.querySelectorAll('[data-aba-painel]').forEach(function (p) { p.hidden = p.dataset.abaPainel !== aba.dataset.aba; });
        pagina.querySelectorAll('input[name="aba"]').forEach(function (i) {
            if (i.closest('form') && i.closest('form').method.toLowerCase() === 'post') {
                i.value = aba.dataset.aba;
            }
        });
        var url = new URL(window.location.href);
        url.searchParams.set('aba', aba.dataset.aba);
        window.history.replaceState(null, '', url.toString());
    });

    var opcoesMs = function (ms) {
        return Array.from(ms.querySelectorAll('.usuariosonline-ms-opcao'));
    };

    var atualizarMs = function (ms) {
        var lista = opcoesMs(ms);
        var marcadas = lista.filter(function (o) { return o.querySelector('input').checked; });
        var nome = function (o) { return o.querySelector('.usuariosonline-ms-rotulo').childNodes[0].textContent.trim(); };
        var texto = ms.querySelector('.usuariosonline-ms-texto');
        if (!marcadas.length) {
            texto.textContent = ms.dataset.placeholder || 'Selecione...';
        } else if (marcadas.length <= 3) {
            texto.textContent = marcadas.map(nome).join(', ');
        } else {
            texto.textContent = marcadas.slice(0, 2).map(nome).join(', ') + ' e mais ' + (marcadas.length - 2);
        }
        ms.querySelector('.usuariosonline-ms-contador').textContent = marcadas.length + ' de ' + lista.length + ' selecionado(s)';
        var visiveis = lista.filter(function (o) { return !o.hidden; });
        var n = visiveis.filter(function (o) { return o.querySelector('input').checked; }).length;
        var todos = ms.querySelector('[data-usuariosonline-ms-todos]');
        todos.checked = visiveis.length > 0 && n === visiveis.length;
        todos.indeterminate = n > 0 && n < visiveis.length;
    };

    var reordenarMs = function (ms) {
        var caixa = ms.querySelector('.usuariosonline-ms-opcoes');
        opcoesMs(ms).sort(function (a, b) {
            var ca = a.querySelector('input').checked ? 0 : 1;
            var cb = b.querySelector('input').checked ? 0 : 1;
            return ca !== cb ? ca - cb : a.dataset.label.localeCompare(b.dataset.label, 'pt-BR', { numeric: true });
        }).forEach(function (o) { caixa.appendChild(o); });
    };

    var filtrarMs = function (ms) {
        var termo = ms.querySelector('.usuariosonline-ms-busca').value.trim().toLowerCase();
        opcoesMs(ms).forEach(function (o) { o.hidden = termo !== '' && o.dataset.label.indexOf(termo) < 0; });
        atualizarMs(ms);
    };

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-usuariosonline-ms-abrir]');
        document.querySelectorAll('[data-usuariosonline-ms]').forEach(function (ms) {
            var drop = ms.querySelector('.usuariosonline-ms-dropdown');
            if (abrir && ms.contains(abrir)) {
                drop.hidden = !drop.hidden;
                if (!drop.hidden) {
                    ms.querySelector('.usuariosonline-ms-busca').focus();
                }
            } else if (!ms.contains(e.target)) {
                drop.hidden = true;
            }
        });
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.usuariosonline-ms-busca')) {
            filtrarMs(e.target.closest('[data-usuariosonline-ms]'));
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('.usuariosonline-ms-busca')) {
            e.preventDefault();
        }
    });

    document.addEventListener('change', function (e) {
        var ms = e.target.closest('[data-usuariosonline-ms]');
        if (!ms) {
            return;
        }
        if (e.target.matches('[data-usuariosonline-ms-todos]')) {
            opcoesMs(ms).forEach(function (o) {
                if (!o.hidden) {
                    o.querySelector('input').checked = e.target.checked;
                    o.classList.toggle('selected', e.target.checked);
                }
            });
        } else if (e.target.closest('.usuariosonline-ms-opcao')) {
            e.target.closest('.usuariosonline-ms-opcao').classList.toggle('selected', e.target.checked);
            var busca = ms.querySelector('.usuariosonline-ms-busca');
            if (busca.value) {
                busca.value = '';
                filtrarMs(ms);
                busca.focus();
            }
        } else {
            return;
        }
        reordenarMs(ms);
        atualizarMs(ms);
    });

    // ------------------------------------------------------------------ início

    var iniciar = function () {
        document.querySelectorAll('[data-usuariosonline-ms]').forEach(atualizarMs);
        iniciarPagina();
        sinal(true);
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
