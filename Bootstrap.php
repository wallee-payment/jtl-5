<?php declare(strict_types=1);

namespace Plugin\jtl_wallee;

if (file_exists(dirname(__DIR__) . '/jtl_wallee/vendor/autoload.php')) {
	require_once dirname(__DIR__) . '/jtl_wallee/vendor/autoload.php';
}

use JTL\Events\Dispatcher;
use JTL\phpQuery\phpQuery;
use JTL\Plugin\Bootstrapper;
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
	 * @inheritdoc
	 */
	public function boot(Dispatcher $dispatcher)
	{
		parent::boot($dispatcher);
		$plugin = $this->getPlugin();
		
		if (Shop::isFrontend()) {
			$apiClient = WalleeHelper::getApiClient($plugin->getId());
			if (empty($apiClient)) {
				// Need to setup plugin
				return;
			}
			$handler = new FrontendHandler($plugin, $apiClient, $this->getDB());
			$dispatcher->listen('shop.hook.' . \HOOK_SMARTY_OUTPUTFILTER, [$handler, 'contentUpdate']);
			$dispatcher->listen('shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB_ENDE, function ($args) use ($handler, $plugin, $apiClient) {
				
				if (!str_contains(\strtolower($_SESSION['Zahlungsart']->cModulId), 'wallee') || empty($apiClient)) {
					return;
				}
				
				$redirectUrl = $handler->getRedirectUrlAfterCreatedTransaction($args['oBestellung']);
				
				header("Location: " . $redirectUrl);
				exit;
			});
			
			$dispatcher->listen('shop.hook.' . \HOOK_BESTELLVORGANG_PAGE_STEPZAHLUNG, function () use ($handler) {
				$smarty = Shop::Smarty();
				$paymentMethods = $handler->getPaymentMethodsForForm($smarty);
				$smarty->assign('Zahlungsarten', $paymentMethods);
			});
		} else {
			$dispatcher->listen('shop.hook.' . \HOOK_PLUGIN_SAVE_OPTIONS, function () use ($plugin) {
				$apiClient = WalleeHelper::getApiClient($plugin->getId());
				if (empty($apiClient)) {
					return;
				}
				$this->installPaymentMethodsOnSettingsSave();
				$this->registerWebhooksOnSettingsSave();
			});
		}
	}
	
	protected function installPaymentMethodsOnSettingsSave(): void
	{
		$paymentService = new WalleePaymentService(WalleeHelper::getApiClient($this->getPlugin()->getId()), $this->getPlugin()->getId());
		$paymentService->syncPaymentMethods();
	}
	
	protected function registerWebhooksOnSettingsSave(): void
	{
		$webhookService = new WalleeWebhookService(WalleeHelper::getApiClient($this->getPlugin()->getId()), $this->getPlugin()->getId());
		$webhookService->install();
	}
	
	public function renderAdminMenuTab(string $tabName, int $menuID, JTLSmarty $smarty): string
	{
		$tabsProvider = new AdminTabProvider($this->getPlugin(), $this->getDB(), $smarty);
		return $tabsProvider->createOrdersTab($menuID);
	}
}