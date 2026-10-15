<?php declare(strict_types=1);

use JTL\Shop;
use JTL\Alert\Alert;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\WalleeApiClient;
use Plugin\jtl_wallee\WalleeHelper;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

// This page has exactly one job: clean up after a failed or cancelled payment and send
// the customer back to the payment step. Every step below talks to the portal, the
// database or the mailer, so all of it is wrapped - a failing cleanup must never turn
// into an HTTP 500 that traps the customer outside the checkout.
$transactionId = $_SESSION['transactionId'] ?? null;
WalleeHelper::log("failed_payment: User landed on failure page. TransactionId: " . ($transactionId ?? 'NONE'));

// Tracks whether the customer already got an explanation. If the cleanup blows up
// before that, the catch below has to supply a generic one.
$customerNotified = false;

if ($transactionId) {
    try {
        $translations = WalleeHelper::getTranslations($plugin->getLocalization(), [
            'jtl_wallee_payment_cancelled',
        ], false);

        $apiClient = new WalleeApiClient($plugin->getId());
        $transactionService = new WalleeTransactionService($apiClient->getApiClient(), $plugin);
        $transaction = $transactionService->getTransactionFromPortal($transactionId);
        unset($_SESSION['transactionId']);

        // A transaction the customer aborted himself carries no user failure message,
        // and AlertService silently drops empty messages - the customer would end up
        // back in the checkout without any explanation. Fall back to our own text.
        $errorMessage = $transaction?->getUserFailureMessage() ?: '';
        if ($errorMessage === '') {
            $errorMessage = $translations['jtl_wallee_payment_cancelled'] ?? '';
        }

        if ($errorMessage !== '') {
            $alertHelper = Shop::Container()->getAlertService();
            $alertHelper->addAlert(Alert::TYPE_ERROR, $errorMessage, md5($errorMessage), ['saveInSession' => true]);
            $customerNotified = true;
        }

        if (str_contains(strtolower($errorMessage), 'timeout')) {
            unset($_SESSION['arrayOfPossibleMethods']);
        }

        $orderId = (int)($transaction?->getMetaData()['orderId'] ?? 0);
        if ($orderId === 0) {
            $localTransaction = $transactionService->getLocalWalleeTransactionById((string)$transactionId);
            $orderId = (int)($localTransaction->order_id ?? 0);
        }

        if ($orderId > 0) {
            // If confirmTransaction already rolled this order back (e.g. HTTP 442 from
            // address validation), the service has set this flag. Skip cleanup so we
            // don't run cancelOrder twice and double-restore stock via the additive UPDATE.
            $alreadyRolledBack = (int)($_SESSION['wlen_rollback_done_order_id'] ?? 0) === $orderId;
            unset($_SESSION['wlen_rollback_done_order_id']);

            if ($alreadyRolledBack) {
                WalleeHelper::log("failed_payment: Order $orderId was already rolled back by confirmTransaction. Skipping cleanup.");
            } else {
                // Native JTL cancellation.
                // This triggers the standard 'Storno' routine which releases stock reservations
                // correctly and updates the order status to 'Cancelled'.
                // We use this instead of manual stock updates to ensure data integrity.
                $orderCancelled = $transactionService->cancelOrderOnce($orderId);
                if ($orderCancelled) {
                    $transactionService->restoreStock($orderId);
                }
            }
        }
    } catch (\Throwable $e) {
        unset($_SESSION['transactionId']);
        WalleeHelper::log(
            'failed_payment: cleanup failed (' . get_class($e) . ' / HTTP ' . $e->getCode() . '): ' . $e->getMessage()
        );

        // The customer is redirected back to the payment step either way. If the failure
        // happened before the portal message could be shown, they would land there
        // without a single word of explanation - so fall back to a generic alert.
        if (!$customerNotified) {
            try {
                $fallbackTranslations = WalleeHelper::getTranslations($plugin->getLocalization(), [
                    'jtl_wallee_payment_failed_generic',
                ], false);
                $fallbackMessage = $fallbackTranslations['jtl_wallee_payment_failed_generic'] ?? '';
                if ($fallbackMessage !== '') {
                    Shop::Container()->getAlertService()->addAlert(
                        Alert::TYPE_ERROR,
                        $fallbackMessage,
                        'wallee_payment_failed',
                        ['saveInSession' => true]
                    );
                }
            } catch (\Throwable $alertError) {
                // Nothing left to do - never block the redirect over a missing alert.
                WalleeHelper::log(
                    'failed_payment: generic alert could not be shown: ' . $alertError->getMessage()
                );
            }
        }

        // Also report it where a merchant actually looks, otherwise a stranded order
        // looks like "the customer simply never paid".
        try {
            Shop::Container()->getLogService()->error(
                'Wallee: cleanup on the failed payment page did not complete for transaction {tid}. '
                . 'The order may still need to be cancelled manually. ({err})',
                ['tid' => $transactionId, 'err' => $e->getMessage()]
            );
        } catch (\Throwable $loggingError) {
            // Nothing left to do - never block the redirect over a logging problem.
        }
    }
}

$linkHelper = Shop::Container()->getLinkService();
\header('Location: ' . $linkHelper->getStaticRoute('bestellvorgang.php') . '?editZahlungsart=1');
exit;
