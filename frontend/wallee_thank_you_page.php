<?php declare(strict_types=1);

use JTL\Checkout\Bestellung;
use JTL\Shop;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\WalleeApiClient;
use Plugin\jtl_wallee\WalleeHelper;
use Wallee\Sdk\Model\TransactionState;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$transactionId = (int)$_GET['tID'] ?? null;
if ($transactionId) {
    $apiClient = new WalleeApiClient($plugin->getId());
    $transactionService = new WalleeTransactionService($apiClient->getApiClient(), $plugin);

    // In case error from api, we will try to fetch transaction again
    $transaction = $transactionService->getTransactionFromPortal($transactionId);
	Shop::Container()->getLogService()->notice(
	  "Transaction found. Starting to create order."
	);
    $createAfterPayment = (int)$transaction->getMetaData()['orderAfterPayment'] ?? 1;
    if ($createAfterPayment) {
        $orderId = (int)$transaction->getMetaData()['orderId'];
        $order = new Bestellung($orderId);
        $orderId = (int)$order->kBestellung;
    } else {
		Shop::Container()->getLogService()->notice(
		  "Order was not created. We created it previously and returning the ID."
		);
        $localTransaction = $transactionService->getLocalWalleeTransactionById((string)$transactionId);
        $orderId = (int) $localTransaction->order_id;
    }

    if ($orderId > 0) {
        $state = $transaction->getState();
        // Fallback for JTL-Wawi synchronization. 
        // If the transaction is already authorized or fulfilled in the portal, we ensure the sync flag 
        // is set to 'N' (LET_SYNC_TO_WAWI) immediately. This prevents the order from being hidden from 
        // Wawi if the asynchronous webhook is delayed or fails.
        if ($state === TransactionState::FULFILL || $state === TransactionState::AUTHORIZED) {
            WalleeHelper::log("thank_you_page: Transaction $transactionId is successful ($state). Resetting cAbgeholt to LET_SYNC_TO_WAWI ('N').");
            // Goes through the same one time release as the webhook. Releasing here directly
            // would leave the marker unset, and the webhook arriving afterwards would hand
            // the order to Wawi a second time.
            $transactionService->releaseOrderToWawiOnce($orderId);
        }
    }
} else {
    Shop::Container()->getLogService()->notice(
        "No transaction ID."
    );
}

$_SESSION['transactionId'] = null;
$_SESSION['Warenkorb'] = null;
$_SESSION['transactionId'] = null;
$_SESSION['arrayOfPossibleMethods'] = null;

$linkHelper = Shop::Container()->getLinkService();
if ($orderId > 0) {
    $bestellid = Shop::Container()->getDB()->select('tbestellid', 'kBestellung', $orderId);
}
$controlId = $bestellid->cId ?? '';
$url = $linkHelper->getStaticRoute('bestellabschluss.php') . '?i=' . $controlId;

\header('Location: ' . $url);
exit;
