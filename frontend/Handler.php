<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\frontend;

use JTL\Checkout\Bestellung;
use JTL\DB\DbInterface;
use JTL\Plugin\PluginInterface;
use JTL\Shop;
use Plugin\jtl_wallee\Services\WalleeTransactionService;
use Wallee\Sdk\ApiClient;
use Plugin\jtl_wallee\WalleeHelper;

final class Handler
{
	/** @var PluginInterface */
	private $plugin;
	
	/** @var ApiClient|null */
	private $apiClient;
	
	/** @var DbInterface|null */
	private $db;
	
	/** @var WalleeTransactionService */
	private $transactionService;
	
	/**
	 * Handler constructor.
	 * @param PluginInterface $plugin
	 * @param DbInterface|null $db
	 * @param ApiClient $apiClient
	 */
	public function __construct(PluginInterface $plugin, ApiClient $apiClient, ?DbInterface $db = null)
	{
		$this->plugin = $plugin;
		$this->apiClient = $apiClient;
		$this->db = $db ?? Shop::Container()->getDB();
		$this->transactionService = new WalleeTransactionService($this->apiClient, $this->plugin);
	}
	
	public function createAndConfirmTransaction(Bestellung $order)
	{
		$createdTransaction = $this->transactionService->createTransaction($order);
		$this->transactionService->confirmTransaction($createdTransaction);
		$transactionId = $createdTransaction->getId();
		
		$_SESSION['transactionId'] = $transactionId;
		
		return $transactionId;
	}
	
	public function getRedirectUrlAfterCreatedTransaction($createdTransactionId, $orderData): string
	{
		$config = WalleeHelper::getConfigByID($this->plugin->getId());
		$spaceId = $config[WalleeHelper::SPACE_ID];
		
		// TODO create setting with options ['payment_page', 'iframe'];
		$integration = 'iframe';
		$_SESSION['transactionID'] = $createdTransactionId;
		
		if ($integration == 'payment_page') {
			$redirectUrl = $this->apiClient->getTransactionPaymentPageService()
			  ->paymentPageUrl($spaceId, $createdTransactionId);
			
			return $redirectUrl;
		}
		
		$_SESSION['javascriptUrl'] = $this->apiClient->getTransactionIframeService()
		  ->javascriptUrl($spaceId, $createdTransactionId);
		$_SESSION['appJsUrl'] = $this->plugin->getPaths()->getBaseURL() . 'frontend/js/wallee-app.js';
		
		$paymentMethod = $this->transactionService->getTransactionPaymentMethod($createdTransactionId, $spaceId);
		if (empty($paymentMethod)) {
			$failedUrl = Shop::getURL() . '/' . WalleeHelper::PLUGIN_CUSTOM_PAGES['fail-page'][$_SESSION['cISOSprache']];
			header("Location: " . $failedUrl);
			exit;
		}
		
		$_SESSION['possiblePaymentMethodId'] = $paymentMethod->getId();
		$_SESSION['possiblePaymentMethodName'] = $paymentMethod->getName();
		$_SESSION['orderData'] = $orderData;

		return WalleeHelper::PLUGIN_CUSTOM_PAGES['payment-page'][$_SESSION['cISOSprache']];
	}
	
	public function contentUpdate(array $args): void
	{
		if (Shop::getPageType() === \PAGE_BESTELLVORGANG) {
			$this->setPaymentMethodLogoSize();
		}
	}
	
	public function setPaymentMethodLogoSize(): void
	{
		global $step;
		
		if (in_array($step, ['Zahlung', 'Versand'])) {
			$paymentMethodsCss = '<link rel="stylesheet" href="' . $this->plugin->getPaths()->getBaseURL() . 'frontend/css/checkout-payment-methods.css">';
			pq('head')->append($paymentMethodsCss);
		}
	}
	
}
