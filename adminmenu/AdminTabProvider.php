<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\adminmenu;

use JTL\Backend\Permissions;
use JTL\Catalog\Currency;
use JTL\Catalog\Product\Preise;
use JTL\Checkout\Bestellung;
use JTL\Checkout\Zahlungsart;
use JTL\DB\DbInterface;
use JTL\DB\ReturnType;
use JTL\Helpers\Form;
use JTL\Helpers\PaymentMethod;
use JTL\Helpers\Request;
use JTL\Language\LanguageHelper;
use JTL\Pagination\Pagination;
use JTL\Plugin\Payment\Method;
use JTL\Plugin\Plugin;
use JTL\Plugin\PluginInterface;
use JTL\Session\Frontend;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_wallee\Services\WalleeRefundService;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\WalleeHelper;
use stdClass;
use Wallee\Sdk\ApiClient;
use Wallee\Sdk\Model\{TransactionInvoiceState, TransactionState};

class AdminTabProvider
{
    const ACTION_COMPLETE = 'complete';
    const ACTION_CANCEL = 'cancel';
    const ACTION_DOWNLOAD_INVOICE = 'download_invoice';
    const ACTION_DOWNLOAD_PACKAGING_SLIP = 'download_packaging_slip';
    const ACTION_REFUND = 'refund';
    const ACTION_ORDER_DETAILS = 'order_details';
    const ACTION_SEARCH_ORDERS = 'search_orders';

    /**
     * Actions this tab is responsible for. Anything else is left to the shop so an unrelated
     * action parameter does not swallow the whole plugin page.
     */
    const HANDLED_ACTIONS = [
        self::ACTION_COMPLETE,
        self::ACTION_CANCEL,
        self::ACTION_DOWNLOAD_INVOICE,
        self::ACTION_DOWNLOAD_PACKAGING_SLIP,
        self::ACTION_REFUND,
        self::ACTION_ORDER_DETAILS,
    ];

    /**
     * Actions that do not change any state and are triggered by a download link. They still
     * have to carry a valid token, but may arrive as GET.
     */
    const GET_ALLOWED_ACTIONS = [
        self::ACTION_DOWNLOAD_INVOICE,
        self::ACTION_DOWNLOAD_PACKAGING_SLIP,
    ];

    const REFUND_SUBMIT_TOKEN_SESSION_KEY = 'wallee_refund_submit_tokens';

    const REFUND_SUBMIT_TOKEN_LIMIT = 20;

    const FILE_DOWNLOAD_ALLOWED_STATES = [
        'FULFILL',
        'PAID',
        'REFUNDED',
        'PARTIALLY REFUNDED'
    ];

    /**
     * @var PluginInterface
     */
    private $plugin;

    /**
     * @var DbInterface
     */
    private $db;

    /**
     * @var JTLSmarty
     */
    private $smarty;
    
    /**
     * @var ApiClient|null
     */
    private $apiClient;
    
    /**
     * @var WalleeTransactionService
     */
    private $transactionService;
    
    /**
     * @var WalleeRefundService
     */
    private $refundService;
    
    
    /**
     * @param PluginInterface $plugin
     * @param DbInterface $db
     * @param JTLSmarty $smarty
     */
    public function __construct(PluginInterface $plugin, DbInterface $db, JTLSmarty $smarty)
    {
        $this->plugin = $plugin;
        $this->db = $db;
        $this->smarty = $smarty;

        $this->apiClient = WalleeHelper::getApiClient($plugin->getId());
        if (empty($this->apiClient)) {
            return;
        }

        $this->transactionService = new WalleeTransactionService($this->apiClient, $this->plugin);
        $this->refundService = new WalleeRefundService($this->apiClient, $this->plugin);
    }

    /**
     *
     * @param int $menuID
     * @return string
     */
    public function createOrdersTab(int $menuID): string
    {
        $action = $this->getRequestValue('action');
        $orders = [];
        if ($action !== null && \in_array($action, self::HANDLED_ACTIONS, true)) {
            if (!$this->isActionRequestValid($action)) {
                $this->rejectAction($action);
            }
            $this->handleAction($action);
            exit;
        }
        
        $searchQueryString = $_GET['q'] ?? '';
        $sqlConditions = [];
        $params = [];
        $orders = [];
        
        if ($searchQueryString) {
            $searchQuery = '%' . $searchQueryString . '%';
            $sqlConditions[] = 'ord.cBestellNr LIKE :searchQuery';
            $sqlConditions[] = 'tkunde.cVorname LIKE :searchQuery';
            $sqlConditions[] = 'ord.cZahlungsartName LIKE :searchQuery';
            $params[':searchQuery'] = $searchQuery;
        }
        
        $sqlConditionStr = $sqlConditions ? 'WHERE ' . implode(' OR ', $sqlConditions) : '';
        $ordersQuantity = $this->db->query('SELECT transaction_id FROM wallee_transactions', ReturnType::AFFECTED_ROWS);
        $pagination = (new Pagination('wallee-orders'))->setItemCount($ordersQuantity)->assemble();
        $query = '
            SELECT ord.kBestellung, ord.fGesamtsumme, plugin.transaction_id, plugin.state
            FROM tbestellung ord
            JOIN wallee_transactions plugin ON ord.kBestellung = plugin.order_id
            JOIN tkunde ON tkunde.kKunde = ord.kKunde
            ' . $sqlConditionStr . '
            ORDER BY ord.kBestellung DESC
        ';
        
        $orderArr = $this->db->executeQueryPrepared($query . ' LIMIT ' . $pagination->getLimitSQL(), $params, ReturnType::ARRAY_OF_OBJECTS);
        foreach ($orderArr as $order) {
            $orderId = (int)$order->kBestellung;
            $ordObj = new Bestellung($orderId);
            $ordObj->fuelleBestellung(true, 0, false);
            $orderDetails = [
              'orderDetails' => $ordObj,
              'wallee_transaction_id' => $order->transaction_id,
              'wallee_state' => $order->state,
              'total_amount' => (float)$order->fGesamtsumme
            ];
            $orders[$orderId] = $orderDetails;
        }

        $paymentStatus = WalleeHelper::getPaymentStatusWithTransations($this->plugin->getLocalization());
        $translations = WalleeHelper::getTranslations($this->plugin->getLocalization(), [
            'jtl_wallee_order_number',
            'jtl_wallee_customer',
            'jtl_wallee_payment_method',
            'jtl_wallee_order_status',
            'jtl_wallee_amount',
            'jtl_wallee_there_are_no_orders',
            'jtl_wallee_search',
        ]);
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $currentUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        
        return $this->smarty->assign('orders', $orders)
            ->assign('currentUrl', $currentUrl)
            ->assign('searchQueryString', $searchQueryString)
            ->assign('adminUrl', $this->plugin->getPaths()->getadminURL())
            ->assign('translations', $translations)
            ->assign('pagination', $pagination)
            ->assign('pluginId', $this->plugin->getID())
            ->assign('postUrl', Shop::getURL() . '/' . \PFAD_ADMIN . 'plugin.php?kPlugin=' . $this->plugin->getID())
            ->assign('paymentStatus', $paymentStatus)
            ->assign('hash', 'plugin-tab-' . $menuID)
            ->assign('tplPath', $this->plugin->getPaths()->getAdminPath() . 'templates')
            ->fetch($this->plugin->getPaths()->getAdminPath() . 'templates/wallee_orders.tpl');
    }
    
    /**
     * @param string $action
     * @return void
     */
    private function handleAction(string $action): void
    {
        $transactionId = $this->getRequestValue('transactionId');
        if ($transactionId === null && $action !== self::ACTION_ORDER_DETAILS) {
            Shop::Container()->getLogService()->error('No transaction ID provided on action ' . $action);
            exit;
        }

        switch ($action) {
            case self::ACTION_COMPLETE:
                $this->completeTransaction($transactionId);
                break;
            
            case self::ACTION_CANCEL:
                $this->cancelTransaction($transactionId);
                break;
            
            case self::ACTION_DOWNLOAD_INVOICE:
                $this->downloadInvoice($transactionId);
                break;
            
            case self::ACTION_DOWNLOAD_PACKAGING_SLIP:
                $this->downloadPackagingSlip($transactionId);
                break;
            
            case self::ACTION_REFUND:
                $amount = (float) ($this->getRequestValue('amount') ?? 0);
                $this->processRefund($transactionId, $amount);
                break;
            
            case self::ACTION_ORDER_DETAILS:
                $menuID = (int) ($this->getRequestValue('menuID') ?? 0);
                $this->displayOrderInfo($_POST, $menuID);
                break;
        }
    }

    /**
     * Every action of this tab has to be proven to come from the backend itself: a state change
     * only via POST with a valid JTL token, a download link at least with a token in the query
     * string. Without that an external page could trigger a refund or a cancellation in the
     * browser of a logged in administrator.
     *
     * @param string $action
     * @return bool
     */
    private function isActionRequestValid(string $action): bool
    {
        if (!$this->hasPermissionForActions()) {
            return false;
        }

        if (!$this->isPostRequest() && !\in_array($action, self::GET_ALLOWED_ACTIONS, true)) {
            return false;
        }

        return Form::validateToken();
    }

    /**
     * The plugin page itself is permission protected by the shop, this is the second barrier
     * for the actions that are executed from within the rendered tab.
     *
     * @return bool
     */
    private function hasPermissionForActions(): bool
    {
        $adminAccount = Shop::Container()->getAdminAccount();
        if (!$adminAccount->getIsAuthenticated()) {
            return false;
        }

        return $adminAccount->permission(Permissions::PLUGIN_DETAIL_VIEW_ALL)
            || $adminAccount->permission(Permissions::PLUGIN_DETAIL_VIEW_ID . $this->plugin->getID());
    }

    /**
     * @param string $action
     * @return void
     */
    private function rejectAction(string $action): void
    {
        $this->logRejectedAction($action, $this->getRequestValue('transactionId'), 'rejected, request could not be verified');

        if (!\headers_sent()) {
            \http_response_code(403);
        }

        $translations = WalleeHelper::getTranslations($this->plugin->getLocalization(), [
            'jtl_wallee_invalid_request',
        ]);

        print $translations['jtl_wallee_invalid_request'];
        exit;
    }

    /**
     * @return bool
     */
    private function isPostRequest(): bool
    {
        return \strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }

    /**
     * Reads a single request value without merging GET into POST, so a state changing action
     * cannot be smuggled in through the query string.
     *
     * @param string $key
     * @return string|null
     */
    private function getRequestValue(string $key): ?string
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? null;

        return \is_scalar($value) ? (string)$value : null;
    }

    /**
     * @param string $action
     * @param string|null $transactionId
     * @param string $reason
     * @return void
     */
    private function logRejectedAction(string $action, ?string $transactionId, string $reason): void
    {
        $account = Shop::Container()->getAdminAccount()->account();
        $message = \sprintf(
            'Action "%s" %s. Transaction: %s, order: %s, admin: %s, method: %s, IP: %s',
            $action,
            $reason,
            $transactionId ?? '-',
            $this->getRequestValue('order_id') ?? '-',
            $account !== false ? ($account->cLogin ?? '-') : '-',
            \strtoupper($_SERVER['REQUEST_METHOD'] ?? '-'),
            Request::getRealIP()
        );

        Shop::Container()->getLogService()->error('Wallee: ' . $message);
    }

    /**
     * Hands out a single use token for the refund form. This is not a second CSRF token, the
     * CSRF check is the JTL token in isActionRequestValid(). It only makes sure that the same
     * refund submit cannot be processed twice: the portal gets a random external id per call
     * and its refunded amount lags right after a refund was created, so two identical posts
     * would both pass the amount check.
     *
     * @param string $transactionId
     * @return string
     */
    private function createRefundSubmitToken(string $transactionId): string
    {
        $token = Shop::Container()->getCryptoService()->randomString(32);
        $tokens = $_SESSION[self::REFUND_SUBMIT_TOKEN_SESSION_KEY] ?? [];
        $tokens[$token] = $transactionId;
        if (\count($tokens) > self::REFUND_SUBMIT_TOKEN_LIMIT) {
            $tokens = \array_slice($tokens, -self::REFUND_SUBMIT_TOKEN_LIMIT, null, true);
        }
        $_SESSION[self::REFUND_SUBMIT_TOKEN_SESSION_KEY] = $tokens;

        return $token;
    }

    /**
     * @param string|null $token
     * @param string $transactionId
     * @return bool
     */
    private function consumeRefundSubmitToken(?string $token, string $transactionId): bool
    {
        $tokens = $_SESSION[self::REFUND_SUBMIT_TOKEN_SESSION_KEY] ?? [];
        if ($token === null || ($tokens[$token] ?? null) !== $transactionId) {
            return false;
        }

        unset($_SESSION[self::REFUND_SUBMIT_TOKEN_SESSION_KEY][$token]);

        return true;
    }
    
    /**
     * @param string|null $transactionId
     * @param float $amount
     * @return void
     */
    private function processRefund(?string $transactionId, float $amount): void
    {
        $localTransaction = $this->transactionService->getLocalWalleeTransactionById((string)$transactionId);
        if ($localTransaction === null) {
            $this->logRejectedAction(self::ACTION_REFUND, $transactionId, 'rejected, transaction does not belong to this shop');
            $this->printRefundNotPossible();
        }

        if (!$this->consumeRefundSubmitToken($this->getRequestValue('refundSubmitToken'), (string)$transactionId)) {
            $this->logRejectedAction(self::ACTION_REFUND, $transactionId, 'rejected, refund was already submitted');
            $this->printRefundNotPossible();
        }

        $transaction = $this->transactionService->getTransactionFromPortal($transactionId);
        if ($transaction === null || $transaction->getState() !== TransactionState::FULFILL) {
            $this->logRejectedAction(self::ACTION_REFUND, $transactionId, 'rejected, transaction is not in a refundable state');
            $this->printRefundNotPossible();
        }

        $requestedAmount = \round($amount, 2);
        if ($requestedAmount <= 0) {
            $this->logRejectedAction(self::ACTION_REFUND, $transactionId, 'rejected, invalid amount ' . $amount);
            $this->printRefundNotPossible();
        }

        $refundableAmount = $this->refundService->getRefundableAmount($transaction);
        if ($requestedAmount > $refundableAmount) {
            $this->logRejectedAction(self::ACTION_REFUND, $transactionId, 'rejected, amount ' . $amount . ' exceeds the refundable amount ' . $refundableAmount);
            $this->printRefundNotPossible();
        }

        $this->refundService->makeRefund($transactionId, $amount);
        $order = new Bestellung((int) $localTransaction->order_id);
        
        $paymentMethodEntity = new Zahlungsart((int)$order->kZahlungsart);
        $paymentMethod = new Method($paymentMethodEntity->cModulId);
        $paymentMethod->setOrderStatusToPaid($order);
        
        $incomingPayment = new stdClass();
        $incomingPayment->fBetrag = -1 * $amount;
        $incomingPayment->cISO = $transaction->getCurrency();
        $incomingPayment->cZahlungsanbieter = $order->cZahlungsartName;
        $paymentMethod->addIncomingPayment($order, $incomingPayment);
    }

    /**
     * @return void
     */
    private function printRefundNotPossible(): void
    {
        if (!\headers_sent()) {
            \http_response_code(409);
        }

        $translations = WalleeHelper::getTranslations($this->plugin->getLocalization(), [
            'jtl_wallee_refund_not_possible',
        ]);

        print $translations['jtl_wallee_refund_not_possible'];
        exit;
    }

    private function displayOrderInfo(array $post, int $menuID): string
    {
        $translations = WalleeHelper::getTranslations($this->plugin->getLocalization(), [
            'jtl_wallee_order_number',
            'jtl_wallee_transaction_id',
            'jtl_wallee_transaction_state',
            'jtl_wallee_transaction_no_possible_actions',
            'jtl_wallee_complete',
            'jtl_wallee_cancel',
            'jtl_wallee_refunds',
            'jtl_wallee_download_invoice',
            'jtl_wallee_download_packaging_slip',
            'jtl_wallee_make_refund',
            'jtl_wallee_amount_to_refund',
            'jtl_wallee_refund_now',
            'jtl_wallee_refunded_amount',
            'jtl_wallee_amount',
            'jtl_wallee_refund_date',
            'jtl_wallee_total',
            'jtl_wallee_no_refunds_info_text',
        ]);

        $transactonId = $this->getRequestValue('transaction_id');
        if ($transactonId === null) {
            return '';
        }
        
        $orderId = (int) ($post['order_id'] ?? 0);
        $orderNo = (string) ($post['order_no'] ?? '');
        $transactionState = (string) ($post['transaction_state'] ?? '');
        $requestedTotalAmount = (float) ($post['total_amount'] ?? 0);
        
        $transaction = $this->transactionService->getTransactionFromPortal($transactonId);
        $currency = $transaction->getCurrency();

        $refunds = $this->refundService->getRefunds((string)$orderId, $currency);
        $totalRefundsAmount = $this->refundService->getTotalRefundsAmount($refunds);

        preg_match('/([\d\.,]+)/', Preise::getLocalizedPriceString($requestedTotalAmount, $currency, true), $matches);
        $number = str_replace(',', '.', $matches[1]);
        $totalAmount = number_format((float)$number, 2, '.', '');

        $amountToBeRefunded = round(floatval($totalAmount) - $totalRefundsAmount, 2);

        $showRefundsForm = $transactionState !== 'REFUNDED' && $amountToBeRefunded > 0;

        $result = $this->smarty->assign('adminUrl', $this->plugin->getPaths()->getadminURL())
            ->assign('refunds', $refunds)
            ->assign('totalAmount', $totalAmount)
            ->assign('totalAmountText', Preise::getLocalizedPriceString($requestedTotalAmount, $currency, true))
            ->assign('totalRefundsAmount', $totalRefundsAmount)
            ->assign('totalRefundsAmountText', Preise::getLocalizedPriceWithoutFactor($totalRefundsAmount, Currency::fromISO($currency), true))
            ->assign('amountToBeRefunded', $amountToBeRefunded)
            ->assign('showRefundsForm', $showRefundsForm)
            ->assign('refundSubmitToken', $showRefundsForm ? $this->createRefundSubmitToken($transactonId) : '')
            ->assign('orderNo', $orderNo)
            ->assign('transactionId', $transactonId)
            ->assign('transactionState', $transactionState)
            ->assign('translations', $translations)
            ->assign('menuId', '#plugin-tab-' . $menuID)
            ->assign('postUrl', Shop::getURL() . '/' . \PFAD_ADMIN . 'plugin.php?kPlugin=' . $this->plugin->getID())
            ->fetch($this->plugin->getPaths()->getAdminPath() . 'templates/wallee_order_details.tpl');

        print $result;
        exit;
    }

    /**
     * @param $transactionId
     * @return void
     */
    private function completeTransaction($transactionId): void
    {
        $transaction = $this->transactionService->getLocalWalleeTransactionById($transactionId);
        if ($transaction === null) {
            $this->logRejectedAction(self::ACTION_COMPLETE, $transactionId, 'skipped, transaction does not belong to this shop');
            return;
        }

        $portalTransaction = $this->transactionService->getTransactionFromPortal($transactionId);
        if ($portalTransaction !== null && $portalTransaction->getState() === TransactionState::AUTHORIZED) {
            $this->transactionService->completePortalTransaction($transactionId);
        }
    }

    /**
     * @param $transactionId
     * @return void
     */
    private function cancelTransaction($transactionId): void
    {
        $transaction = $this->transactionService->getLocalWalleeTransactionById($transactionId);
        if ($transaction === null) {
            $this->logRejectedAction(self::ACTION_CANCEL, $transactionId, 'skipped, transaction does not belong to this shop');
            return;
        }

        $portalTransaction = $this->transactionService->getTransactionFromPortal($transactionId);
        if ($portalTransaction !== null && $portalTransaction->getState() === TransactionState::AUTHORIZED) {
            $this->transactionService->cancelPortalTransaction($transactionId);
        }
    }

    /**
     * @param $transactionId
     * @return void
     */
    private function downloadInvoice($transactionId): void
    {
        $transaction = $this->transactionService->getLocalWalleeTransactionById($transactionId);
        if ($transaction !== null && \in_array(strtoupper($transaction->state), self::FILE_DOWNLOAD_ALLOWED_STATES)) {
            $this->transactionService->downloadInvoice($transactionId);
        }
    }

    /**
     * @param $transactionId
     * @return void
     */
    private function downloadPackagingSlip($transactionId): void
    {
        $transaction = $this->transactionService->getLocalWalleeTransactionById($transactionId);
        if ($transaction !== null && \in_array(strtoupper($transaction->state), self::FILE_DOWNLOAD_ALLOWED_STATES)) {
            $this->transactionService->downloadPackagingSlip($transactionId);
        }
    }
}
