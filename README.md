# Usuários Online para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

Mostra **quem está usando o GLPI agora**, ativo ou ausente, com os chamados de cada pessoa. Também guarda o **histórico diário de presença**: primeiro e último acesso, tempo online e tempo ativo.

## O que o plugin faz

### Presença em tempo real
- Toda pessoa logada envia um **sinal** periódico de presença: **ativo** quando está usando, ou **ausente** depois de alguns minutos sem mexer.
- Várias abas abertas contam como **uma presença só**, sem ficar trocando entre ativo e ausente.
- Ao clicar em sair, a pessoa sai da lista na hora. Depois do tempo sem sinal configurado, ela é considerada offline.

### Painel na barra superior
- Ícone com o **número de pessoas online**.
- Lista com o nome, a situação, desde quando está online e os **contadores de chamados**: atribuídos, como requerente, como observador e do grupo.
- Cada contador abre a **busca nativa** já filtrada.

### Página Usuários online
Fica em *Ferramentas → Usuários online* e tem duas abas:
- **Agora:** tabela ordenável de quem está online, com filtro por grupo;
- **Histórico:** presença por período, com o primeiro e o último acesso, o tempo online e o **tempo ativo** de cada dia. Exportação **CSV**.

## Configuração

Opções da página de configuração:
- perfis que **veem** o painel e perfis que **aparecem** na lista;
- grupos exibidos, e se quem está sem grupo aparece;
- contadores de chamados exibidos;
- intervalo do sinal, minutos até ficar ausente e segundos até ficar offline;
- retenção do histórico, aplicada por uma tarefa automática diária.

---

## Download e instalação

1. Baixe o arquivo `usuariosonline-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/usuariosonline/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/usuariosonline
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install usuariosonline -u <usuário administrador>
   php bin/console plugin:activate usuariosonline
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/usuariosonline` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install usuariosonline -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).