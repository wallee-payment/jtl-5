<?php declare(strict_types=1);

use JTL\Shop;
use JTL\Alert\Alert;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\WalleeApiClient;
use Plugin\jtl_wallee\WalleeHelper;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$transactionId = $_SESSION['transactionId'] ?? null;
$translations = WalleeHelper::getTranslations($plugin->getLocalization(), [
    'jtl_wallee_payment_not_available_by_country_or_currency',
], false);
$errorMessage = $translations['jtl_wallee_payment_not_available_by_country_or_currency'];

if ($transactionId) {
    $apiClient = new WalleeApiClient($plugin->getId());
    $transactionService = new WalleeTransactionService($apiClient->getApiClient(), $plugin);
    $transaction = $transactionService->getTransactionFromPortal($transactionId);
    unset($_SESSION['transactionId']);

    $errorMessage = $transaction->getUserFailureMessage() ?? '';
    $alertHelper = Shop::Container()->getAlertService();
    $alertHelper->addAlert(Alert::TYPE_ERROR, $errorMessage, md5($errorMessage), ['saveInSession' => true]);

    if (str_contains(strtolower($errorMessage), 'timeout')) {
        unset($_SESSION['arrayOfPossibleMethods']);
    }
}

if (!function_exists('restoreCart')) {
    function restoreCart($cartItems)
    {
        foreach ($cartItems as $cartItem) {
            if ($cartItem->kArtikel === 0) {
                continue;
            }

            $quantityBefore = (int)$cartItem->fLagerbestandVorAbschluss;
            if ($quantityBefore < 1) {
                continue;
            }

            Shop::Container()->getDB()->update(
                'tartikel',
                'kArtikel',
                (int)$cartItem->kArtikel,
                (object)['fLagerbestand' => $quantityBefore]
            );
        }
    }
}

if (isset($_SESSION['orderData']) && !empty($_SESSION['orderData']->Positionen)) {
    $cartItems = $_SESSION['orderData']->Positionen;
    if ($cartItems) {
        restoreCart($cartItems);
    }
}

$linkHelper = Shop::Container()->getLinkService();
\header('Location: ' . $linkHelper->getStaticRoute('bestellvorgang.php') . '?editZahlungsart=1');
exit;
