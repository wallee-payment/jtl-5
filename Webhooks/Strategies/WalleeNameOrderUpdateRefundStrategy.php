<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Webhooks\Strategies;

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

	public function __construct(WalleeRefundService $refundService, WalleeTransactionService $transactionService)
	{
		$this->refundService = $refundService;
		$this->transactionService = $transactionService;
		$this->orderService = new WalleeOrderService();
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

		$orderId = (int)$refund->getTransaction()->getMetaData()['orderId'];
		$this->refundService->createRefundRecord((int)$entityId, $orderId, $refund->getAmount());

		$transaction = $refund->getTransaction();
		$amountToBeRefunded = round(floatval($transaction->getCompletedAmount()) - $transaction->getRefundedAmount(), 2);

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
