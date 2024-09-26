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
    private const MAX_RETRIES = 5;
    private const PAUSE_DURATION = 2; // seconds
    
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
                
                $transaction = $this->transactionService->getTransactionFromPortal($entityId);
                if ($transaction->getState() === TransactionState::FULFILL) {
                    $this->waitUntilOrderIsCreated($transaction);
                }
                
                $orderUpdater->updateOrderStatus($entityId);
                break;
            
            case WalleeHelper::TRANSACTION_INVOICE:
                $orderUpdater->setStrategy(new WalleeNameOrderUpdateTransactionInvoiceStrategy($this->transactionService));
                $transactionInvoice = $this->transactionService->getTransactionInvoiceFromPortal($entityId);
                $transaction = $transactionInvoice->getCompletion()
                  ->getLineItemVersion()
                  ->getTransaction();

                if ($transaction->getState() === TransactionState::FULFILL) {
                    $this->waitUntilOrderIsCreated($transaction);
                }
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
    
    /**
     * Order ID sometimes comes too late, so we need to wait first until order is created.
     * @param $transaction
     * @return void
     */
    private function waitUntilOrderIsCreated($transaction): void
    {
        $orderNr = $transaction->getMetaData()['order_nr'];
        
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $orderData = $this->transactionService->getOrderIfExists($orderNr);
            
            if (isset($orderData->kBestellung)) {
                return; // Order found, exit the method
            }
            
            sleep(self::PAUSE_DURATION);
        }
        
        // Log a warning or handle the case where the order was not found after retries
        Shop::Container()->getLogService()->warning(
          "Order not found for Transaction {$transaction->getId()} after " . self::MAX_RETRIES . " attempts."
        );
    }
    
}

