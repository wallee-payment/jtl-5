<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Services;

use JTL\Cart\CartItem;
use JTL\Catalog\Currency;
use JTL\Catalog\Product\Preise;
use JTL\Checkout\Bestellung;
use JTL\Helpers\PaymentMethod;
use JTL\Session\Frontend;
use JTL\Shop;
use Plugin\jtl_wallee\WalleeHelper;
use Wallee\Sdk\ApiClient;
use Wallee\Sdk\Model\{AddressCreate,
    LineItemCreate,
    LineItemType,
    Refund,
    RefundCreate,
    RefundType,
    Transaction,
    TransactionCreate,
    TransactionPending,
    TransactionState};

class WalleeRefundService
{
    /**
     * @var ApiClient $apiClient
     */
    protected ApiClient $apiClient;

    /**
     * @var $spaceId
     */
    protected $spaceId;

    /**
     * @var $transactionService
     */
    protected $transactionService;

    public function __construct(ApiClient $apiClient, $plugin)
    {
        $config = WalleeHelper::getConfigByID($plugin->getId());
        $spaceId = $config[WalleeHelper::SPACE_ID];

        $this->apiClient = $apiClient;
        $this->spaceId = $spaceId;
        $this->transactionService = new WalleeTransactionService($apiClient, $plugin);
    }

    /**
     * @param string $transactionId
     * @param float $amount
     * @return array
     * @throws \InvalidArgumentException when the amount cannot be refunded
     * @throws \RuntimeException when the portal rejects the refund
     */
    public function makeRefund(string $transactionId, float $amount): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount should be greater than 0');
        }

        $transaction = $this->transactionService->getTransactionFromPortal($transactionId);
        $transactionAmount = $transaction->getAuthorizationAmount();
        if ($amount > $transactionAmount) {
            throw new \InvalidArgumentException('Please make sure you are trying to refund correct amount of money');
        }

        if ($transaction->getState() !== TransactionState::FULFILL) {
            // Not an error, but the caller asked for money to be returned and none was, so
            // it cannot pass silently either: an order cancelled at this point leaves the
            // customer charged.
            Shop::Container()->getLogService()->notice(
                'Wallee: no refund for transaction ' . $transactionId . ', it is in state '
                . $transaction->getState() . ' rather than ' . TransactionState::FULFILL
            );

            return [];
        }

        $refundPayload = (new RefundCreate())
            ->setAmount(\round($amount, 2))
            ->setTransaction($transactionId)
            ->setMerchantReference((string)mb_substr((string)$transaction->getMerchantReference(), 0, 100, 'UTF-8'))
            ->setExternalId(uniqid('refund_', true))
            ->setType(RefundType::MERCHANT_INITIATED_ONLINE);

        if (!$refundPayload->valid()) {
            throw new \RuntimeException(
                'Refund payload invalid: ' . json_encode($refundPayload->listInvalidProperties())
            );
        }

        try {
            $this->apiClient->getRefundService()->refund($this->spaceId, $refundPayload);
        } catch (\Exception $e) {
            throw new \RuntimeException($this->describeApiFailure($e), 0, $e);
        }

        return [];
    }

    /**
     * Digs the readable part out of an SDK exception message.
     *
     * The SDK folds the raw response body into the message, so the wording the API sent is
     * only reachable by pulling the JSON object back out of it. Falls back to the message
     * itself when there is nothing to pull.
     *
     * @param \Throwable $e
     * @return string
     */
    private function describeApiFailure(\Throwable $e): string
    {
        $detectJsonPattern = '/
				\{              # { character
					(?:         # non-capturing group
						[^{}]   # anything that is not a { or }
						|       # OR
						(?R)    # recurses the entire pattern
					)*          # previous group zero or more times
				\}              # } character
				/x';

        if (!preg_match_all($detectJsonPattern, $e->getMessage(), $matches) || empty($matches[0][0])) {
            return $e->getMessage();
        }

        $errorData = \json_decode($matches[0][0]);

        return $errorData->message ?? $e->getMessage();
    }

    /**
     * @param string $refundId
     * @return Refund
     */
    public function getRefundFromPortal(string $refundId)
    {
        return $this->apiClient->getRefundService()->read($this->spaceId, $refundId);
    }

    /**
     * @param string $orderId
     * @param string $currency
     * @return array
     */
    public function getRefunds(string $orderId, string $currency = null): array
    {
        if (!$currency) {
            $currency = Frontend::getCurrency();
        }

        $refunds = Shop::Container()
            ->getDB()
            ->selectAll('wallee_refunds', 'order_id', $orderId);

        foreach ($refunds as $refund) {
            $refund->amountText = Preise::getLocalizedPriceWithoutFactor(floatval($refund->amount), Currency::fromISO($currency), true);
        }

        return $refunds;
    }

    /**
     * @param array $refunds
     * @return float
     */
    public function getTotalRefundsAmount(array $refunds): float
    {
        $total = 0;
        foreach ($refunds as $refund) {
            $total += $refund->amount;
        }

        return round($total, 2);
    }

    /**
     * @param int $refundId
     * @param int $orderId
     * @param float $amount
     */
    public function createRefundRecord(int $refundId, int $orderId, float $amount): void
    {
        $newRefund = new \stdClass();
        $newRefund->refund_id = $refundId;
        $newRefund->order_id = $orderId;
        $newRefund->amount = $amount;
        $newRefund->created_at = date('Y-m-d H:i:s');

        Shop::Container()->getDB()->insert('wallee_refunds', $newRefund);
    }
}

