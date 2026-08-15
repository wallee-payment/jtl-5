<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Webhooks\Strategies;

use JTL\Checkout\Bestellung;
use JTL\Checkout\Zahlungsart;
use JTL\Plugin\Payment\Method;
use JTL\Plugin\Plugin;
use JTL\Shop;
use Plugin\jtl_wallee\WalleeHelper;
use Plugin\jtl_wallee\Services\WalleeOrderService;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\Webhooks\Strategies\Interfaces\WalleeOrderUpdateStrategyInterface;
use Wallee\Sdk\Model\Transaction;
use Wallee\Sdk\Model\TransactionState;

class WalleeNameOrderUpdateTransactionStrategy implements WalleeOrderUpdateStrategyInterface
{
    /**
     * @var Plugin $plugin
     */
    private $plugin;

    /**
     * @var WalleeTransactionService $transactionService
     */
    private $transactionService;

    /**
     * @var WalleeOrderService $orderService
     */
    private $orderService;

    public function __construct(WalleeTransactionService $transactionService, Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->transactionService = $transactionService;
        $this->orderService = new WalleeOrderService();
    }

    /**
     * @param string $transactionId
     * @return void
     */
    public function updateOrderStatus(string $entityId): void
    {
        $transaction = $this->transactionService->getTransactionFromPortal($entityId);
        if ($transaction === null) {
            print 'Transaction ' . $entityId . ' not found';
            exit;
        }

        $transactionId = $transaction->getId();
        $transactionState = $transaction->getState();

        $orderId = (int)$transaction->getMetaData()['orderId'];
        if ($orderId === 0) {
            print 'Order not found for transaction ' . $entityId;
            exit;
        }

        WalleeHelper::log("webhook strategy: Processing transaction $transactionId with state $transactionState for order $orderId");
        switch ($transactionState) {
            case TransactionState::FULFILL:
                $order = new Bestellung($orderId);
                // addIncomingPayment below sets the order to BEZAHLT unconditionally, so the
                // status has to be read before it runs to still see a cancelled order.
                $statusBeforePayment = (int)$order->cStatus;
                if ($order && $statusBeforePayment !== \BESTELLUNG_STATUS_BEZAHLT) {
                    $orderData = $order->fuelleBestellung();
                    $this->transactionService->addIncomingPayment((string)$transactionId, $orderData, $transaction);
                    $this->transactionService->handleNextOrderReferenceNumber($transaction->getMetaData()['order_no'] ?? null);
                }
                $this->releaseOrderToWawi($orderId, $statusBeforePayment);
                break;

            case TransactionState::AUTHORIZED:
                $order = new Bestellung($orderId);
                $statusBeforePayment = (int)$order->cStatus;
                if ($order && $statusBeforePayment === \BESTELLUNG_STATUS_OFFEN) {
                    $this->transactionService->handleNextOrderReferenceNumber($transaction->getMetaData()['order_no'] ?? null);
                }
                $this->releaseOrderToWawi($orderId, $statusBeforePayment);
                break;

            case TransactionState::FAILED:
            case TransactionState::DECLINE:
            case TransactionState::VOIDED:
                WalleeHelper::log("webhook strategy: Transaction $transactionId in state $transactionState. Cancelling order $orderId.");
                $this->transactionService->cancelOrderOnce($orderId);
                print 'Order ' . $orderId . ' status was updated to cancelled';
                break;
        }
        $this->transactionService->updateTransactionStatus($transactionId, $transactionState);
    }

    /**
     * Hands a successfully paid order over to Wawi.
     *
     * Kept outside the order status checks above: a transaction invoice webhook can mark the
     * order paid before this one arrives, and an order that is no longer OFFEN or is already
     * BEZAHLT would then never have its sync flag released, leaving it invisible to Wawi.
     *
     * @param int $orderId
     * @param int $statusBeforePayment order status as it was before this webhook touched it
     * @return void
     */
    private function releaseOrderToWawi(int $orderId, int $statusBeforePayment): void
    {
        if ($statusBeforePayment === \BESTELLUNG_STATUS_STORNO) {
            return;
        }

        $this->transactionService->releaseOrderToWawiOnce($orderId);
    }
}
