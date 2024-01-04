<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Webhooks\Strategies;

use JTL\Checkout\Bestellung;
use JTL\Checkout\Zahlungsart;
use JTL\Customer\Customer;
use JTL\Mail\Mail\Mail;
use JTL\Mail\Mailer;
use JTL\Plugin\Data\PaymentMethod;
use JTL\Plugin\Payment\Method;
use JTL\Plugin\Plugin;
use JTL\Session\Frontend;
use JTL\Shop;
use Plugin\jtl_wallee\Services\WalleeOrderService;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Plugin\jtl_wallee\Webhooks\Strategies\Interfaces\WalleeOrderUpdateStrategyInterface;
use Plugin\jtl_wallee\WalleeHelper;
use stdClass;
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
        $transactionId = $transaction->getId();

        $localTransaction = $this->transactionService->getLocalWalleeTransactionById((string)$transactionId);
        $orderId = (int)$localTransaction->order_id;
        $transactionState = $transaction->getState();

        switch ($transactionState) {
            case TransactionState::FULFILL:
                $order = new Bestellung($orderId);
                $this->transactionService->addIncommingPayment((string)$transactionId, $order, $transaction);
                break;

            case TransactionState::PROCESSING:
                $this->transactionService->updateTransactionStatus($transactionId, $transactionState);
                print 'Order ' . $orderId . ' status was updated to processing. Triggered by Transaction Invoice webhook.';
                break;

            case TransactionState::AUTHORIZED:
                $this->transactionService->updateTransactionStatus($transactionId, $transactionState);
                if ($orderId && $this->isSendConfirmationEmail()) {
                    $this->sendConfirmationMail($orderId);
                }
                break;

            case TransactionState::DECLINE:
            case TransactionState::VOIDED:
            case TransactionState::FAILED:
                if ($orderId > 0) {
                    $order = new Bestellung($orderId);
                    $paymentMethodEntity = new Zahlungsart((int)$order->kZahlungsart);
                    $moduleId = new Method($paymentMethodEntity->cModulId) ?? '';
                    $paymentMethod = new Method($moduleId);
                    $paymentMethod->cancelOrder($orderId);
                }
                $this->transactionService->updateTransactionStatus($transactionId, $transactionState);
                print 'Order ' . $orderId . ' status was updated to cancelled';
                break;
        }
    }

    private function isSendConfirmationEmail()
    {
        $config = WalleeHelper::getConfigByID($this->plugin->getId());
        $sendConfirmationEmail = $config[WalleeHelper::SEND_CONFIRMATION_EMAIL] ?? null;

        return $sendConfirmationEmail === 'YES';
    }

    /**
     * @param int $orderId
     * @return void
     */
    private function sendConfirmationMail(int $orderId): void
    {
        Shop::Container()
            ->getDB()->update(
                'wallee_transactions',
                ['order_id'],
                [$orderId],
                (object)[
                    'confirmation_email_sent' => 1,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            );

        $order = new Bestellung($orderId);
        $order->fuelleBestellung(false);
        $customer = new Customer($order->kKunde);
        $data = new stdClass();
        $mailer = Shop::Container()->get(Mailer::class);
        $mail = new Mail();

        $data->tkunde = $customer;
        $data->tbestellung = $order;
        if ($customer->cMail !== '') {
            $mailer->send($mail->createFromTemplateID(\MAILTEMPLATE_BESTELLBESTAETIGUNG, $data));
        }
    }
}
