<?php declare(strict_types=1);

use JTL\Checkout\Bestellung;
use JTL\Shop;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\WalleeApiClient;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */


function getTransactionWithRetry($transactionService, $transactionId, $maxRetries = 5, $delaySeconds = 1) {
    $attempt = 0;
    while ($attempt < $maxRetries) {
        try {
            $transaction = $transactionService->getTransactionFromPortal($transactionId);
            if ($transaction !== null) {
                return $transaction;
            }
        } catch (Exception $e) {
            Shop::Container()->getLogService()->notice(
                "Attempt #$attempt to get transaction was unsuccessful: " . $e->getMessage()
            );
        }

        $attempt++;
        sleep($delaySeconds);
    }

    Shop::Container()->getLogService()->notice(
        "Transaction was not fetched after $maxRetries attempts."
    );
    return null;
}

$transactionId = (int)$_GET['tID'] ?? null;

if ($transactionId) {
    $apiClient = new WalleeApiClient($plugin->getId());
    $transactionService = new WalleeTransactionService($apiClient->getApiClient(), $plugin);

    // In case error from api, we will try to fetch transaction again
    $transaction = getTransactionWithRetry($transactionService, $transactionId);
	Shop::Container()->getLogService()->notice(
	  "Transaction found. Starting to create order."
	);
    $createAfterPayment = (int)$transaction->getMetaData()['orderAfterPayment'] ?? 1;
    if ($createAfterPayment) {
		Shop::Container()->getLogService()->notice(
		  "Creating order after payment for transaction {$transactionId}."
		);
        $orderNr = $transaction->getMetaData()['order_nr'];
        $data = $transactionService->getOrderIfExists($orderNr);
        if ($data === null) {
            $orderId = $transactionService->createOrderAfterPayment($transactionId);
            $transactionService->waitUntilOrderIsCreated($transaction);
            Shop::Container()->getLogService()->notice(
                "New order has been created. OrderId: {$orderId}. TransactionID: {$transactionId}"
            );
        } else {
            $orderId = (int)$data->kBestellung;
            Shop::Container()->getLogService()->notice(
                "Order was not created, because it was found in DB {$orderId}"
            );
        }
    } else {
		Shop::Container()->getLogService()->notice(
		  "Order was not created. We created it previously and returning the ID."
		);
        $localTransaction = $transactionService->getLocalWalleeTransactionById((string)$transactionId);
        $orderId = (int) $localTransaction->order_id;
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
    $bestellid = $this->db->select('tbestellid', 'kBestellung', $orderId);
}
$controlId = $bestellid->cId ?? '';
$url = $linkHelper->getStaticRoute('bestellabschluss.php') . '?i=' . $controlId;

\header('Location: ' . $url);
exit;
