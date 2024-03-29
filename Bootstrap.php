<?php declare(strict_types=1);

namespace Plugin\jtl_wallee;

if (file_exists(dirname(__DIR__) . '/jtl_wallee/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/jtl_wallee/vendor/autoload.php';
}

use JTL\Checkout\Bestellung;
use JTL\Checkout\Zahlungsart;
use JTL\Events\Dispatcher;
use JTL\Helpers\PaymentMethod;
use JTL\phpQuery\phpQuery;
use JTL\Plugin\Bootstrapper;
use JTL\Plugin\Helper;
use JTL\Plugin\Payment\Method;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_paypal\paymentmethod\PendingPayment;
use Plugin\jtl_wallee\adminmenu\AdminTabProvider;
use Plugin\jtl_wallee\frontend\Handler as FrontendHandler;
use Plugin\jtl_wallee\Services\WalleePaymentService;
use Plugin\jtl_wallee\Services\WalleeWebhookService;
use Wallee\Sdk\ApiClient;
use Wallee\Sdk\Model\PaymentMethodConfiguration;

/**
 * Class Bootstrap
 * @package Plugin\jtl_wallee
 */
class Bootstrap extends Bootstrapper
{
    /**
     * @var WalleePaymentService|null
     */
    private ?WalleePaymentService $paymentService = null;

    /**
     * @var ApiClient|null
     */
    private ?ApiClient $apiClient = null;

    /**
     * @inheritdoc
     */
    public function boot(Dispatcher $dispatcher)
    {
        parent::boot($dispatcher);
        $plugin = $this->getPlugin();

        if (Shop::isFrontend()) {
            $apiClient = WalleeHelper::getApiClient($plugin->getId());
            if (empty($apiClient)) {
                // Need to run composer install
                return;
            }
            $handler = new FrontendHandler($plugin, $apiClient, $this->getDB());
            $this->listenFrontendHooks($dispatcher, $handler);
        } else {
            $this->listenPluginSaveOptionsHook($dispatcher);
        }
    }

    /**
     * @inheritdoc
     */
    public function uninstalled(bool $deleteData = true)
    {
        parent::uninstalled($deleteData);
        $this->updatePaymentMethodStatus(WalleePaymentService::STATUS_DISABLED);
    }

    /**
     * @inheritDoc
     */
    public function enabled(): void
    {
        parent::enabled();
        $this->updatePaymentMethodStatus();
    }

    /**
     * @inheritDoc
     */
    public function disabled(): void
    {
        parent::disabled();
        $this->updatePaymentMethodStatus(WalleePaymentService::STATUS_DISABLED);
    }

    /**
     * @inheritDoc
     */
    public function renderAdminMenuTab(string $tabName, int $menuID, JTLSmarty $smarty): string
    {
        $tabsProvider = new AdminTabProvider($this->getPlugin(), $this->getDB(), $smarty);
        return $tabsProvider->createOrdersTab($menuID);
    }

    /**
     * @return void
     */
    protected function installPaymentMethodsOnSettingsSave(): void
    {
        $paymentService = $this->getPaymentService();
        $paymentService?->syncPaymentMethods();
    }

    /**
     * @return void
     */
    protected function registerWebhooksOnSettingsSave(): void
    {
        $apiClient = $this->getApiClient();
        if ($apiClient === null) {
            return;
        }

        $webhookService = new WalleeWebhookService($apiClient, $this->getPlugin()->getId());
        $webhookService->install();
    }

    /**
     * @param Dispatcher $dispatcher
     * @param FrontendHandler $handler
     * @return void
     */
    private function listenFrontendHooks(Dispatcher $dispatcher, FrontendHandler $handler): void
    {
        $dispatcher->listen('shop.hook.' . \HOOK_SMARTY_OUTPUTFILTER, [$handler, 'contentUpdate']);
        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB_ENDE, function ($args) use ($handler) {
            if (isset($_SESSION['finalize']) && $_SESSION['finalize'] === true) {
                unset($_SESSION['finalize']);
            } else {
                if (isset($_SESSION['Zahlungsart']->cModulId) && str_contains(\strtolower($_SESSION['Zahlungsart']->cModulId), 'wallee')) {
                    $redirectUrl = $handler->getRedirectUrlAfterCreatedTransaction($args['oBestellung']);
                    header("Location: " . $redirectUrl);
                    exit;
                }
            }
        });

        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLVORGANG_PAGE_STEPZAHLUNG, function () use ($handler) {
            $smarty = Shop::Smarty();
            $paymentMethods = $handler->getPaymentMethodsForForm($smarty);
            $smarty->assign('Zahlungsarten', $paymentMethods);
        });

        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLUNGEN_XML_BEARBEITESET, function ($args) use ($handler) {
            $order = $args['oBestellung'] ?? [];
            if ((int)$order->cStatus === \BESTELLUNG_STATUS_BEZAHLT) {
                $order = new Bestellung($args['oBestellung']->kBestellung);

                $paymentMethodEntity = new Zahlungsart((int)$order->kZahlungsart);
                $moduleId = $paymentMethodEntity->cModulId ?? '';
                $paymentMethod = new Method($moduleId);
                $paymentMethod->setOrderStatusToPaid($order);

                Shop::Container()
                    ->getDB()->update(
                        'tbestellung',
                        ['kBestellung',],
                        [$args['oBestellung']->kBestellung],
                        (object)['cAbgeholt' => 'Y']
                    );
            }
        });

        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLUNGEN_XML_BESTELLSTATUS, function ($args) use ($handler) {
            $handler->completeOrderAfterWawi($args);
        });

        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLUNGEN_XML_BEARBEITESTORNO, function ($args) use ($handler) {
            $handler->cancelOrderAfterWawi($args);
        });
    }

    /**
     * @param Dispatcher $dispatcher
     * @return void
     */
    private function listenPluginSaveOptionsHook(Dispatcher $dispatcher): void
    {
        $dispatcher->listen('shop.hook.' . \HOOK_PLUGIN_SAVE_OPTIONS, function () {
            $this->installPaymentMethodsOnSettingsSave();
            $this->registerWebhooksOnSettingsSave();
        });
    }

    /**
     * @return WalleePaymentService|null
     */
    private function getPaymentService(): ?WalleePaymentService
    {
        $apiClient = $this->getApiClient();
        if ($apiClient === null) {
            return null;
        }

        if ($this->paymentService === null) {
            $this->paymentService = new WalleePaymentService($apiClient, $this->getPlugin()->getId());
        }

        return $this->paymentService;
    }

    /**
     * @return ApiClient|null
     */
    private function getApiClient(): ?ApiClient
    {
        if ($this->apiClient === null) {
            $this->apiClient = WalleeHelper::getApiClient($this->getPlugin()->getId());
        }

        return $this->apiClient;
    }

    /**
     * @param int $status
     * @return void
     */
    private function updatePaymentMethodStatus(int $status = WalleePaymentService::STATUS_ENABLED): void
    {
        $paymentService = $this->getPaymentService();
        $paymentService?->updatePaymentMethodStatus($status);
    }
}
