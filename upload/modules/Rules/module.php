<?php
/*
 *    Rules module made by Coldfire
 *    https://coldfiredzn.com
 *
 *    Using code from the vote module by Partydragen and Samerton
 */

class Rules_Module extends Module {
    private $_rules_language;
    private $_language;

    public function __construct($language, $rules_language, $pages) {
        $this->_rules_language = $rules_language;
        $this->_language = $language;

        $name = 'Rules';
        $author = '<a href="https://coldfiredzn.com" target="_blank" rel="nofollow noopener">Coldfire</a>';
        $module_version = '1.9.1';
        $nameless_version = '2.2.5';

        parent::__construct($this, $name, $author, $module_version, $nameless_version);

        $pages->add('Rules', '/rules', 'pages/rules.php', 'rules', true);
        $pages->add('Rules', '/panel/rules', 'pages/panel/rules.php');
    }

    public function onInstall() {
        $db = DB::getInstance();
        $tables = [
            'rules_settings' => " `id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(20) NOT NULL, `value` varchar(2048) NOT NULL, PRIMARY KEY (`id`)",
            // The released schema misspells categories. Keep the physical name for compatibility.
            'rules_catagories' => " `id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(96) NOT NULL, `icon` varchar(96) NOT NULL, `rules` longtext NOT NULL, PRIMARY KEY (`id`)",
            'rules_buttons' => " `id` int(11) NOT NULL AUTO_INCREMENT, `name` varchar(96) NOT NULL, `link` varchar(96) NOT NULL, PRIMARY KEY (`id`)",
        ];

        foreach ($tables as $table => $schema) {
            if (!$db->showTables($table) && !$db->createTable($table, $schema)) {
                throw new RuntimeException("Could not create nl2_$table.");
            }
        }

        // Seeds a fresh install and repairs only byte-identical shipped defaults on an old install.
        Rules_Migration::migrate(true);

        try {
            $group = DB::getInstance()->get('groups', ['id', '=', 2])->results();
            $group = $group[0];

            $group_permissions = json_decode($group->permissions, TRUE);
            $group_permissions['admincp.rules'] = 1;

            $group_permissions = json_encode($group_permissions);
            DB::getInstance()->update('groups', 2, ['permissions' => $group_permissions]);
        } catch (Exception $e) {
        }
    }

    public function onUninstall()
    {
        // No actions necessary
    }

    public function onEnable()
    {
        // NamelessMC does not call onInstall() again after a module is registered. Re-enabling the
        // module is therefore one supported route into the conservative, idempotent content upgrade.
        Rules_Migration::migrate(true);
    }

    public function onDisable()
    {
        // No actions necessary
    }

    public function onPageLoad($user, $pages, $cache, $smarty, $navs, $widgets, $template) {
        if (defined('PANEL_PAGE') && PANEL_PAGE == 'rules') {
            $template->assets()->include([
                AssetTree::TINYMCE,
            ]);

            $template->addJSScript(Input::createTinyEditor($this->_language, 'InputMessage', null, false, true));
            $template->addJSScript(Input::createTinyEditor($this->_language, 'InputCatagoryRules', null, false, true));
        }
        if (defined('PAGE') && PAGE == 'rules') {
            $template->assets()->include([
                AssetTree::TINYMCE,
            ]);
        }
        PermissionHandler::registerPermissions('Rules', [
            'admincp.rules' => $this->_rules_language->get('rules', 'rules')
        ]);

        $cache->setCache('nav_location');
        if (!$cache->isCached('rules_location')) {
            $link_location = 1;
            $cache->store('rules_location', 1);
        } else {
            $link_location = $cache->retrieve('rules_location');
        }

        $cache->setCache('navbar_icons');
        if (!$cache->isCached('rules_icon')) {
            $icon = '';
        } else {
            $icon = $cache->retrieve('rules_icon');
        }

        $cache->setCache('navbar_order');
        if (!$cache->isCached('rules_order')) {
            // Create cache entry now
            $rules_order = 3;
            $cache->store('rules_order', 3);
        } else {
            $rules_order = $cache->retrieve('rules_order');
        }

        switch ($link_location) {
            case 1:
                $navs[0]->add('rules', $this->_rules_language->get('rules', 'rules'), URL::build('/rules'), 'top', null, $rules_order, $icon);
                break;
            case 2:
                $navs[0]->addItemToDropdown('more_dropdown', 'rules', $this->_rules_language->get('rules', 'rules'), URL::build('/rules'), 'top', null, $icon, $rules_order);
                break;
            case 3:
                $navs[0]->add('rules', $this->_rules_language->get('rules', 'rules'), URL::build('/rules'), 'footer', null, $rules_order, $icon);
                break;
        }

        if (defined('BACK_END')) {
            if ($user->hasPermission('admincp.rules')) {
                $cache->setCache('panel_sidebar');
                if (!$cache->isCached('rules_new_order')) {
                    $order = 14;
                    $cache->store('rules_new_order', 14);
                } else {
                    $order = $cache->retrieve('rules_new_order');
                }

                if (!$cache->isCached('rules_icon')) {
                    $icon = '<i class="nav-icon fas fa-cogs"></i>';
                    $cache->store('rules_icon', $icon);
                } else {
                    $icon = $cache->retrieve('rules_icon');
                }

                $navs[2]->add('rules_divider', mb_strtoupper($this->_rules_language->get('rules', 'rules'), 'UTF-8'), 'divider', 'top', null, $order, '');
                $navs[2]->add('rules', $this->_rules_language->get('rules', 'rules'), URL::build('/panel/rules'), 'top', null, $order + 0.1, $icon);
            }
        }
    }

    public function getDebugInfo(): array
    {
        return [];
    }
}
