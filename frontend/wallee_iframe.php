<?php declare(strict_types=1);

use Plugin\jtl_wallee\WalleeHelper;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$translations = WalleeHelper::getTranslations($plugin->getLocalization(), [
    'jtl_wallee_pay',
    'jtl_wallee_cancel',
], false);

$isTwint = false;
if (strpos(strtolower($_SESSION['Zahlungsart']->cName), "twint") !== false || strpos(strtolower($_SESSION['Zahlungsart']->cTSCode), "twint") !== false) {
    $isTwint = true;
}

$linkHelper = Shop::Container()->getLinkService();
$smarty
    ->assign('translations', $translations)
    ->assign('integration', 'iframe')
    ->assign('paymentName', $_SESSION['Zahlungsart']->angezeigterName[WalleeHelper::getLanguageIso(false)])
    ->assign('paymentId', $_SESSION['possiblePaymentMethodId'])
    ->assign('iframeJsUrl', $_SESSION['javascriptUrl'])
    ->assign('appJsUrl', $_SESSION['appJsUrl'])
    ->assign('isTwint', $isTwint)
    ->assign('spinner', $plugin->getPaths()->getBaseURL() . 'frontend/assets/spinner.gif')
    ->assign('cancelUrl', $linkHelper->getStaticRoute('bestellvorgang.php') . '?editZahlungsart=1')
    ->assign('mainCssUrl', $plugin->getPaths()->getBaseURL() . 'frontend/css/wallee-main.css');
