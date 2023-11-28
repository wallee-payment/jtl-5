<?php declare(strict_types=1);

use Plugin\jtl_wallee\WalleeHelper;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$translations = WalleeHelper::getTranslations($plugin->getLocalization(), [
  'jtl_wallee_pay',
  'jtl_wallee_cancel',
], false);

$smarty
    ->assign('translations', $translations)
    ->assign('integration', 'iframe')
    ->assign('paymentName', $_SESSION['Zahlungsart']->angezeigterName[WalleeHelper::getLanguageIso(false)])
    ->assign('paymentId', $_SESSION['possiblePaymentMethodId'])
    ->assign('iframeJsUrl', $_SESSION['javascriptUrl'])
    ->assign('appJsUrl', $_SESSION['appJsUrl'])
    ->assign('mainCssUrl', $plugin->getPaths()->getBaseURL() . 'frontend/css/wallee-main.css');
