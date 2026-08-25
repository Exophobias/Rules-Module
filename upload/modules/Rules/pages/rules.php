<?php
/*
 *    Rules module made by Coldfire
 *    https://coldfiredzn.com
 *
 *    Using code from the vote module by Partydragen and Samerton
 */

define('PAGE', 'rules');
$page_title = $rules_language->get('rules', 'rules');
require_once(ROOT_PATH . '/core/templates/frontend_init.php');

$rules_message = DB::getInstance()->get("rules_settings", ["name", "=", "rules_message"])->results();
$rules_message = $rules_message[0]->value;

$categories = DB::getInstance()->get("rules_catagories", ["id", "<>", 0])->results();

$categories_array = [];
foreach ($categories as $category) {
    $categories_array[] = [
        'id' => Output::getClean($category->id),
        'name' => Output::getClean($category->name),
        'icon' => Output::getPurified(Output::getDecoded($category->icon)),
        'rules' => Output::getPurified(Output::getDecoded($category->rules))
    ];
}

$buttons = DB::getInstance()->get("rules_buttons", ["id", "<>", 0])->results();

$buttons_array = [];
foreach ($buttons as $button) {
    $buttons_array[] = [
        'name' => Output::getClean($button->name),
        'link' => Output::getClean($button->link),
    ];
}

$template->getEngine()->addVariables([
    'RULES' => $rules_language->get('rules', 'rules'),
    'MESSAGE' => Output::getPurified(Output::getDecoded($rules_message)),
    'CATEGORIES' => $categories_array,
    // Retain the old misspelled variable for third-party templates made against Rules <= 1.8.6.
    'CATAGORIES' => $categories_array,
    'BUTTONS' => $buttons_array
]);

Module::loadPage($user, $pages, $cache, $smarty, [$navigation, $cc_nav, $staffcp_nav], $widgets, $template);

$template->onPageLoad();
$template->addJSScript('$(\'.menu .item\').tab()');

$template->getEngine()->addVariable('WIDGETS_LEFT', $widgets->getWidgets('left'));
$template->getEngine()->addVariable('WIDGETS_RIGHT', $widgets->getWidgets('right'));

require(ROOT_PATH . '/core/templates/navbar.php');
require(ROOT_PATH . '/core/templates/footer.php');

$template->displayTemplate('rules');
