<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Webhooks\Strategies;

use JTL\Shop;
use JTL\Checkout\Bestellung;
use Plugin\jtl_wallee\Services\WalleeOrderService;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\Webhooks\Strategies\Interfaces\WalleeOrderUpdateStrategyInterface;
use Wallee\Sdk\Model\TransactionInvoiceState;
use Wallee\Sdk\Model\TransactionState;

class WalleeNameOrderUpdateTransactionInvoiceStrategy implements WalleeOrderUpdateStrategyInterface
{
    /**
     * @var WalleeTransactionService $transactionService
     */
    public $transactionService;

    /**
     * @var WalleeOrderService $orderService
     */
    private $orderService;

    public function __construct(WalleeTransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
        $this->orderService = new WalleeOrderService();
    }

    /**
     * @param string $transactionId
     * @return void
     */
    public function updateOrderStatus(string $entityId): void
    {
        $transactionInvoice = $this->transactionService->getTransactionInvoiceFromPortal($entityId);
        if ($transactionInvoice === null) {
            print 'Transaction Invoice ' . $entityId . ' not found';
            exit;
        }

        $transaction = $transactionInvoice->getCompletion()
            ->getLineItemVersion()
            ->getTransaction();

        if ($transaction === null) {
            print 'Transaction for Transaction Invoice  ' . $entityId . ' not found';
            exit;
        }

        $transactionId = $transaction->getId();
        $orderId = (int)$transaction->getMetaData()['orderId'];
        if ($orderId === 0) {
            print 'Order not found for transaction ' . $entityId;
            exit;
        }

        switch ($transactionInvoice->getState()) {
            case TransactionInvoiceState::DERECOGNIZED:
                $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_IN_BEARBEITUNG, \BESTELLUNG_STATUS_STORNO);
                $this->transactionService->updateTransactionStatus($transactionId, TransactionState::DECLINE);
                print 'Order ' . $orderId . ' status was updated to cancelled. Triggered by Transaction Invoice webhook.';
                break;

            //case TransactionInvoiceState::NOT_APPLICABLE:
            case TransactionInvoiceState::PAID:
                // Read before anything below moves the order to BEZAHLT, so that an order
                // cancelled by an earlier webhook is still recognisable as cancelled.
                $statusBeforePayment = (int)(new Bestellung($orderId))->cStatus;

                if (!$this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_OFFEN, \BESTELLUNG_STATUS_BEZAHLT)) {
                    $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_IN_BEARBEITUNG, \BESTELLUNG_STATUS_BEZAHLT);
                }

                $order = new Bestellung($orderId);
                $this->transactionService->addIncomingPayment((string)$transactionId, $order, $transaction);
                if ($statusBeforePayment !== \BESTELLUNG_STATUS_STORNO) {
                    $this->transactionService->releaseOrderToWawiOnce($orderId);
                }
                $this->transactionService->handleNextOrderReferenceNumber($transaction->getMetaData()['order_no'] ?? null);
                print 'Order ' . $orderId . ' status was updated to paid. Triggered by Transaction Invoice webhook.';
                break;
        }
    }
}
