<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Webhooks\Strategies;

use JTL\Catalog\Product\Artikel;
use JTL\Checkout\StockUpdater;
use JTL\Helpers\Product;
use JTL\Session\Frontend;
use JTL\Shop;
use Plugin\jtl_wallee\Services\WalleeOrderService;
use Plugin\jtl_wallee\Services\WalleeRefundService;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\Webhooks\Strategies\Interfaces\WalleeOrderUpdateStrategyInterface;
use Wallee\Sdk\Model\TransactionState;

class WalleeNameOrderUpdateRefundStrategy implements WalleeOrderUpdateStrategyInterface
{
    /**
     * @var WalleeRefundService $refundService
     */
    private $refundService;

    /**
     * @var WalleeTransactionService $transactionService
     */
    private $transactionService;

    /**
     * @var WalleeOrderService $orderService
     */
    private $orderService;

    /**
     * The StockUpdater's JTL service.
     *
     * @var JTL\Checkout\StockUpdater
     */
    protected $stockUpdater;

    public function __construct(WalleeRefundService $refundService, WalleeTransactionService $transactionService)
    {
        $this->refundService = $refundService;
        $this->transactionService = $transactionService;
        $this->orderService = new WalleeOrderService();
        $this->stockUpdater = new StockUpdater(Shop::Container()->getDB(), Frontend::getCustomer(), Frontend::getCart());
    }

    /**
     * @param string $transactionId
     * @return void
     */
    public function updateOrderStatus(string $entityId): void
    {
        /**
         * @var \Wallee\Sdk\Model\Refund $refund
         */
        $refund = $this->refundService->getRefundFromPortal($entityId);
        $orderId = (int)$refund->getTransaction()->getMetaData()['orderId'] ?? '';

        if (empty($orderId)) {
            $transactionId = (int)$refund->getTransaction()->getId();
            $localTransaction = $this->transactionService->getLocalWalleeTransactionById((string)$transactionId);
            $orderId = (int)$localTransaction->order_id;
        }
        $this->refundService->createRefundRecord((int)$entityId, $orderId, $refund->getAmount());

        $transaction = $refund->getTransaction();
        $amountToBeRefunded = round(floatval($transaction->getCompletedAmount()) - $transaction->getRefundedAmount(), 2);

        // Restores the stock of the refunded product.
        $reductions = $refund->getReductions();
        if (count($reductions) > 0) {
            foreach ($reductions as $reduction) {
                $quantity = $reduction->getQuantityReduction();
                $line_item_id = $reduction->getLineItemUniqueId();
                if ($quantity > 0) {
                    foreach ($refund->getReducedLineItems() as $line_item) {
                        if ($line_item_id == $line_item->getUniqueId()) {
                            $product_name = $line_item->getName();
                            $product = Shop::Container()->getDB()->select('tartikel', 'cName', $product_name);
                            $productId = (int) ($product->kArtikel ?? 0);
                            if ($productId > 0) {
                                $this->stockUpdater->updateProductStockLevel($productId, -1, $quantity);
                            }
                        }
                    }
                }
            }
        }

        if ($amountToBeRefunded > 0) {
            $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_BEZAHLT, \BESTELLUNG_STATUS_TEILVERSANDT);
            $this->transactionService->updateTransactionStatus($transaction->getId(), 'PARTIALLY REFUNDED');
            print 'Order ' . $orderId . ' status was partially refunded. Triggered by Refund webhook.';
        } else {
            if (!$this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_BEZAHLT, \BESTELLUNG_STATUS_STORNO)) {
                $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_TEILVERSANDT, \BESTELLUNG_STATUS_STORNO);
            }
            $this->transactionService->updateTransactionStatus($transaction->getId(), 'REFUNDED');
            print 'Order ' . $orderId . ' status was refunded. Triggered by Refund webhook.';
        }
    }
}
