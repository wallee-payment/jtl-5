<?php declare(strict_types=1);

use JTL\Checkout\Bestellung;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\WalleeApiClient;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

// When order has to be created after payment
if ($_SESSION['Zahlungsart']?->nWaehrendBestellung ?? null === 1) {
    if ($_SESSION['Warenkorb'] && $_SESSION['transactionId'] && $_SESSION['arrayOfPossibleMethods']) {
        $apiClient = new WalleeApiClient($plugin->getId());
        $transactionService = new WalleeTransactionService($apiClient->getApiClient(), $plugin);
        $transactionService->createOrderAfterPayment();
    }
} else {
    $order = new Bestellung($_SESSION['orderData']->kBestellung);
    $_SESSION['orderData'] = $order->fuelleBestellung(true);
}
$_SESSION['transactionId'] = null;
$_SESSION['Warenkorb'] = null;
$_SESSION['transactionId'] = null;
$_SESSION['arrayOfPossibleMethods'] = null;

$smarty
    ->assign('Bestellung', $_SESSION['orderData'])
    ->assign('mainCssUrl', $plugin->getPaths()->getBaseURL() . 'frontend/css/wallee-loader-main.css');
