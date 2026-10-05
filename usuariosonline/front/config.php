<?php

/**
 * Plugin Usuários Online - atalho para a configuração
 */

Session::checkLoginUser();
Html::redirect(PluginUsuariosonlineConfig::url('config.form.php'));
