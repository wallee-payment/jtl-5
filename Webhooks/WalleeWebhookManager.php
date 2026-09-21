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
use Wallee\Sdk\Model\{Transaction, TransactionState};

/**
 * Class WalleeWebhookManager
 * @package Plugin\jtl_wallee
 */
class WalleeWebhookManager
{
    private const AUTHORIZED_STATES = [
        TransactionState::AUTHORIZED,
        TransactionState::FULFILL,
    ];

    /**
     * @var array $data
     */
    protected $data;

    /**
     * @var string $rawRequestBody Unmodified webhook request body, read exactly once.
     */
    protected string $rawRequestBody;

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
        $this->rawRequestBody = (string)file_get_contents('php://input');
        $this->data = [];
        $this->apiClient = (new WalleeApiClient($plugin->getId()))->getApiClient();
        $this->transactionService = new WalleeTransactionService($this->apiClient, $this->plugin);
        $this->refundService = new WalleeRefundService($this->apiClient, $this->plugin);
    }

    public function listenForWebhooks(): void
    {
        $this->validateRequestSignature();

        // The payload is only decoded once the request is proven authentic.
        $this->data = json_decode($this->rawRequestBody, true) ?? [];

        $listenerEntityTechnicalName = $this->data['listenerEntityTechnicalName'] ?? null;
        if (!$listenerEntityTechnicalName) {
            return;
        }

        $orderUpdater = new WalleeOrderUpdater(new WalleeNameOrderUpdateTransactionStrategy($this->transactionService, $this->plugin));
        $entityId = (string)$this->data['entityId'];

        switch ($listenerEntityTechnicalName) {
            case WalleeHelper::TRANSACTION:
                $orderUpdater->updateOrderStatus($entityId);
                $transactionStateFromWebhook = $this?->data['state'] ?? null;

                $transaction = $this->transactionService->getTransactionFromPortal($entityId);
                $orderId = (int)$transaction->getMetaData()['orderId'] ?? null;

                if ($this->shouldSendAuthorizationEmail($transactionStateFromWebhook, $transaction, $orderId)) {
                    $this->transactionService->sendEmail($orderId, 'authorization');
                }
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

    /**
     * Enforces the webhook payload signature before any business logic runs.
     *
     * @return void
     */
    private function validateRequestSignature(): void
    {
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        if ($signature === '') {
            // Unsigned webhooks are rejected without a signal to retry: legacy senders
            // that never sign payloads would otherwise retry forever.
            $this->rejectRequest('Missing webhook signature header', 200);
            return;
        }

        try {
            $isValid = $this->apiClient->getWebhookEncryptionService()
                ->isContentValid($signature, $this->rawRequestBody);
        } catch (\Exception $e) {
            $this->rejectRequest('Webhook signature could not be verified: ' . $e->getMessage());
            return;
        }

        if ($isValid !== true) {
            $this->rejectRequest('Webhook signature does not match the request body');
        }
    }

    /**
     * Logs the rejected webhook attempt and terminates the request with the given HTTP status.
     *
     * @param string $reason
     * @param int $statusCode
     * @return void
     */
    private function rejectRequest(string $reason, int $statusCode = 400): void
    {
        // Log a fingerprint of the rejected request without trusting or parsing its body.
        Shop::Container()->getLogService()->warning(
            'Wallee webhook rejected: ' . $reason,
            [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'signaturePresent' => !empty($_SERVER['HTTP_X_SIGNATURE']),
                'bodyBytes' => strlen($this->rawRequestBody),
                'bodySha256' => hash('sha256', $this->rawRequestBody),
            ]
        );

        header('Content-Type: application/json', true, $statusCode);
        echo json_encode(['error' => $reason]);
        exit;
    }

    /**
     * Determines if the authorization email should be sent based on webhook state and transaction state.
     *
     * @param string|null $webhookState The state from webhook payload, or null if payload validation is disabled.
     * @param Transaction $transaction The transaction object.
     * @param int|null $orderId The associated order ID.
     * @return bool True if email should be sent, otherwise false.
     */
    private function shouldSendAuthorizationEmail(?string $webhookState, Transaction $transaction, ?int $orderId): bool
    {
        if ($orderId === null) {
            return false;
        }

        if ($webhookState === null) {
            return in_array($transaction->getState(), self::AUTHORIZED_STATES, true);
        }

        return $webhookState === TransactionState::AUTHORIZED;
    }

}

