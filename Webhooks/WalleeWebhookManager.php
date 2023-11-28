<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Webhooks;

use JTL\Plugin\Plugin;
use JTL\Shop;
use Plugin\jtl_wallee\Services\WalleeOrderService;
use Plugin\jtl_wallee\Services\WalleePaymentService;
use Plugin\jtl_wallee\Services\WalleeRefundService;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\Webhooks\Strategies\WalleeNameOrderUpdateRefundStrategy;
use Plugin\jtl_wallee\Webhooks\Strategies\WalleeNameOrderUpdateTransactionInvoiceStrategy;
use Plugin\jtl_wallee\Webhooks\Strategies\WalleeNameOrderUpdateTransactionStrategy;
use Plugin\jtl_wallee\WalleeApiClient;
use Plugin\jtl_wallee\WalleeHelper;
use Wallee\Sdk\ApiClient;
use Wallee\Sdk\Model\{TransactionInvoiceState, TransactionState};

/**
 * Class WalleeWebhookManager
 * @package Plugin\jtl_wallee
 */
class WalleeWebhookManager
{
	/**
	 * @var array $data
	 */
	protected $data;

	/**
	 * @var ApiClient $apiClient
	 */
	protected ApiClient $apiClient;

	/**
	 * @var Plugin $plugin
	 */
	protected $plugin;

	/**
	 * @var WalleeTransactionService $transactionService
	 */
	protected $transactionService;

	/**
	 * @var WalleeRefundService $refundService
	 */
	protected $refundService;

	/**
	 * @var WalleeOrderService $orderService
	 */
	protected $orderService;

	public function __construct(Plugin $plugin)
	{
		$this->plugin = $plugin;
		$this->data = json_decode(file_get_contents('php://input'), true);
		$this->apiClient = (new WalleeApiClient($plugin->getId()))->getApiClient();
		$this->transactionService = new WalleeTransactionService($this->apiClient, $this->plugin);
		$this->refundService = new WalleeRefundService($this->apiClient, $this->plugin);
	}

	public function listenForWebhooks(): void
	{
		$listenerEntityTechnicalName = $this->data['listenerEntityTechnicalName'] ?? null;
		if (!$listenerEntityTechnicalName) {
			return;
		}

		$orderUpdater = new WalleeOrderUpdater(new WalleeNameOrderUpdateTransactionStrategy($this->transactionService, $this->plugin));
		$entityId = (string)$this->data['entityId'];

		switch ($listenerEntityTechnicalName) {
			case WalleeHelper::TRANSACTION:
				$orderUpdater->updateOrderStatus($entityId);
				break;

			case WalleeHelper::TRANSACTION_INVOICE:
				$orderUpdater->setStrategy(new WalleeNameOrderUpdateTransactionInvoiceStrategy($this->transactionService));
				$orderUpdater->updateOrderStatus($entityId);
				break;

			case WalleeHelper::REFUND:
				$orderUpdater->setStrategy(new WalleeNameOrderUpdateRefundStrategy($this->refundService, $this->transactionService));
				$orderUpdater->updateOrderStatus($entityId);
				break;

			case WalleeHelper::PAYMENT_METHOD_CONFIGURATION:
				$paymentService = new WalleePaymentService($this->apiClient, $this->plugin->getId());
				$paymentService->syncPaymentMethods();
				break;
		}
	}

}

