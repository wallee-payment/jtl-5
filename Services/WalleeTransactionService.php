<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Services;

use stdClass;
use JTL\Cart\CartItem;
use JTL\Checkout\Bestellung;
use JTL\Helpers\PaymentMethod;
use JTL\Shop;
use Plugin\jtl_wallee\WalleeHelper;
use Wallee\Sdk\ApiClient;
use Wallee\Sdk\Model\{AddressCreate,
  LineItemCreate,
  LineItemType,
  Transaction,
  TransactionInvoice,
  TransactionCreate,
  TransactionPending,
  TransactionState
};

class WalleeTransactionService
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
	 * @var $spaceViewId
	 */
	protected $spaceViewId;
	
	public function __construct(ApiClient $apiClient, $plugin)
	{
		$config = WalleeHelper::getConfigByID($plugin->getId());
		$spaceId = $config[WalleeHelper::SPACE_ID];
		
		$this->apiClient = $apiClient;
		$this->spaceId = $spaceId;
		$this->spaceViewId = $config[WalleeHelper::SPACE_VIEW_ID];
	}
	
	/**
	 * @param Bestellung $order
	 * @return Transaction
	 */
	public function createTransaction(Bestellung $order): Transaction
	{
		$lineItems = [];
		foreach ($order->Positionen as $product) {
			switch ($product->nPosTyp) {
				case \C_WARENKORBPOS_TYP_VERSANDPOS:
					$lineItems [] = $this->createLineItemShippingItem($product);
					break;
				
				case \C_WARENKORBPOS_TYP_ARTIKEL:
				case \C_WARENKORBPOS_TYP_KUPON:
				case \C_WARENKORBPOS_TYP_GUTSCHEIN:
				case \C_WARENKORBPOS_TYP_ZAHLUNGSART:
				case \C_WARENKORBPOS_TYP_VERSANDZUSCHLAG:
				case \C_WARENKORBPOS_TYP_NEUKUNDENKUPON:
				case \C_WARENKORBPOS_TYP_NACHNAHMEGEBUEHR:
				case \C_WARENKORBPOS_TYP_VERSAND_ARTIKELABHAENGIG:
				case \C_WARENKORBPOS_TYP_VERPACKUNG:
				case \C_WARENKORBPOS_TYP_GRATISGESCHENK:
				default:
					$lineItems[] = $this->createLineItemProductItem($product);
			}
		}
		
		$billingAddress = $this->createBillingAddress();
		$shippingAddress = $this->createShippingAddress();
		
		$transactionPayload = new TransactionCreate();
		$transactionPayload->setCurrency($_SESSION['cWaehrungName']);
		$transactionPayload->setLanguage(WalleeHelper::getLanguageString());
		$transactionPayload->setLineItems($lineItems);
		$transactionPayload->setBillingAddress($billingAddress);
		$transactionPayload->setShippingAddress($shippingAddress);
		
		
		$transactionPayload->setMetaData([
		  'spaceId' => $this->spaceId,
		]);
		
		if (!empty($this->spaceViewId)) {
			$transactionPayload->setSpaceViewId($this->spaceViewId);
		}
		$transactionPayload->setAutoConfirmationEnabled(getenv('WALLEE_AUTOCONFIRMATION_ENABLED') ?: false);
		
		$successUrl = Shop::getURL() . '/' . WalleeHelper::PLUGIN_CUSTOM_PAGES['thank-you-page'][$_SESSION['cISOSprache']];
		$failedUrl = Shop::getURL() . '/' . WalleeHelper::PLUGIN_CUSTOM_PAGES['fail-page'][$_SESSION['cISOSprache']];
		
		$transactionPayload->setSuccessUrl($successUrl);
		$transactionPayload->setFailedUrl($failedUrl);
		$createdTransaction = $this->apiClient->getTransactionService()->create($this->spaceId, $transactionPayload);
		$this->createLocalWalleeTransaction((string)$createdTransaction->getId(), (array)$order);
		
		return $createdTransaction;
	}
	
	/**
	 * @param Transaction $transaction
	 * @return void
	 */
	public function confirmTransaction(Transaction $transaction): void
	{
		$transactionId = $transaction->getId();
		$pendingTransaction = new TransactionPending();
		$pendingTransaction->setId($transactionId);
		$pendingTransaction->setVersion($transaction->getVersion());
		
		$pendingTransaction->setMetaData([
		  'orderId' => $_SESSION['kBestellung'],
		  'spaceId' => $this->spaceId,
		]);
		
		$pendingTransaction->setMerchantReference($_SESSION['BestellNr']);
		
		$this->apiClient->getTransactionService()
		  ->confirm($this->spaceId, $pendingTransaction);
		
		$this->updateLocalWalleeTransaction((string)$transactionId);
		unset($_SESSION['transactionId']);
	}
	
	/**
	 * @param Transaction $transaction
	 * @return void
	 */
	public function updateTransaction(int $transactionId): void
	{
		$pendingTransaction = new TransactionPending();
		$pendingTransaction->setId($transactionId);
		
		$transaction = $this->getTransactionFromPortal($transactionId);
		$pendingTransaction->setVersion($transaction->getVersion());
		
		$billingAddress = $this->createBillingAddress();
		$shippingAddress = $this->createShippingAddress();
		
		$pendingTransaction->setCurrency($_SESSION['cWaehrungName']);
		$pendingTransaction->setBillingAddress($billingAddress);
		$pendingTransaction->setShippingAddress($shippingAddress);
		
		$this->apiClient->getTransactionService()
		  ->update($this->spaceId, $pendingTransaction);
	}
	
	/**
	 * @param string $transactionId
	 * @param int $spaceId
	 * @return mixed|null
	 */
	public function getTransactionPaymentMethod(int $transactionId, string $spaceId)
	{
		$possiblePaymentMethods = $this->apiClient
		  ->getTransactionService()
		  ->fetchPaymentMethods(
			$spaceId,
			$transactionId,
			'iframe'
		  );
		
		$chosenPaymentMethod = \str_replace('jtl_wallee_', '', \strtolower($_SESSION['Zahlungsart']->cModulId));
		$additionalCheck = explode('_wallee', $chosenPaymentMethod);
		if (isset($additionalCheck[0]) && !empty($additionalCheck[0])) {
			$chosenPaymentMethod = \str_replace($additionalCheck[0] . '_', '', $chosenPaymentMethod);
		}
		
		
		foreach ($possiblePaymentMethods as $possiblePaymentMethod) {
			$slug = 'wallee_' . trim(strtolower(WalleeHelper::slugify($possiblePaymentMethod->getName())));
			if ($slug === $chosenPaymentMethod) {
				return $possiblePaymentMethod;
			}
		}
		return null;
	}
	
	public function completePortalTransaction($transactionId): void
	{
		$this->apiClient
		  ->getTransactionCompletionService()
		  ->completeOnline($this->spaceId, $transactionId);
	}
	
	public function cancelPortalTransaction($transactionId): void
	{
		$this->apiClient
		  ->getTransactionVoidService()
		  ->voidOnline($this->spaceId, $transactionId);
	}
	
	/**
	 * @param $transactionId
	 * @return Transaction|null
	 */
	public function getTransactionFromPortal($transactionId): ?Transaction
	{
		return $this->apiClient
		  ->getTransactionService()
		  ->read($this->spaceId, $transactionId);
	}
	
	/**
	 * @param string $transactionId
	 * @return TransactionInvoice|null
	 */
	public function getTransactionInvoiceFromPortal(string $transactionId): ?TransactionInvoice
	{
		return $this->apiClient
		  ->getTransactionInvoiceService()
		  ->read($this->spaceId, $transactionId);
	}
	
	public function fetchPossiblePaymentMethods(string $transactionId)
	{
		return $this->apiClient->getTransactionService()
		  ->fetchPaymentMethods($this->spaceId, $transactionId, 'iframe');
	}
	
	public function updateTransactionStatus($transactionId, $newStatus)
	{
		return Shop::Container()
		  ->getDB()->update(
			'wallee_transactions',
			['transaction_id'],
			[$transactionId],
			(object)['state' => $newStatus]
		  );
	}
	
	/**
	 * @param string $transactionId
	 * @return stdClass|null
	 */
	public function getLocalWalleeTransactionById(string $transactionId): ?stdClass
	{
		return Shop::Container()->getDB()->getSingleObject(
		  'SELECT * FROM wallee_transactions WHERE transaction_id = :transaction_id LIMIT 1',
		  ['transaction_id' => $transactionId]
		);
	}
	
	/**
	 * @param string $transactionId
	 * @return void
	 */
	public function downloadInvoice(string $transactionId): void
	{
		$document = $this->apiClient->getTransactionService()->getInvoiceDocument($this->spaceId, $transactionId);
		if ($document) {
			$this->downloadDocument($document);
		}
	}
	
	/**
	 * @param string $transactionId
	 * @return void
	 */
	public function downloadPackagingSlip(string $transactionId): void
	{
		$document = $this->apiClient->getTransactionService()->getPackingSlip($this->spaceId, $transactionId);
		if ($document) {
			$this->downloadDocument($document);
		}
	}
	
	private function downloadDocument($document)
	{
		$filename = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '_', $document->getTitle()) . '.pdf';
		$filedata = base64_decode($document->getData());
		header('Content-Description: File Transfer');
		header('Content-Type: ' . $document->getMimeType());
		header('Content-Disposition: attachment; filename=' . $filename);
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . strlen($filedata));
		ob_clean();
		flush();
		echo $filedata;
	}
	
	/**
	 * @param CartItem $productData
	 * @return LineItemCreate
	 */
	private function createLineItemProductItem(CartItem $productData): LineItemCreate
	{
		$lineItem = new LineItemCreate();
		$name = \is_array($productData->cName) ? $productData->cName[$_SESSION['cISOSprache']] : $productData->cName;
		$lineItem->setName($name);
		$lineItem->setUniqueId($productData->cArtNr);
		$lineItem->setSku($productData->cArtNr);
		$lineItem->setQuantity($productData->nAnzahl);
		preg_match_all('!\d+!', $productData->cGesamtpreisLocalized[0][$_SESSION['cWaehrungName']], $price);
		$priceDecimal = number_format(floatval(($price[0][0] . '.' . $price[0][1])), 2);
		$lineItem->setAmountIncludingTax($priceDecimal);
		$lineItem->setType(LineItemType::PRODUCT);
		
		return $lineItem;
	}
	
	/**
	 * @param CartItem $productData
	 * @return LineItemCreate
	 */
	private function createLineItemShippingItem(CartItem $productData): LineItemCreate
	{
		$lineItem = new LineItemCreate();
		$name = \is_array($productData->cName) ? $productData->cName[$_SESSION['cISOSprache']] : $productData->cName;
		$lineItem->setName('Shipping: ' . $name);
		$lineItem->setUniqueId('shipping: ' . $name);
		$lineItem->setSku('shipping: ' . $name);
		$lineItem->setQuantity(1);
		preg_match_all('!\d+!', $productData->cGesamtpreisLocalized[0][$_SESSION['cWaehrungName']], $price);
		$priceDecimal = number_format(floatval(($price[0][0] . '.' . $price[0][1])), 2);
		$lineItem->setAmountIncludingTax($priceDecimal);
		$lineItem->setType(LineItemType::SHIPPING);
		
		return $lineItem;
	}
	
	/**
	 * @return AddressCreate
	 */
	private function createBillingAddress(): AddressCreate
	{
		$customer = $_SESSION['Kunde'];

		$billingAddress = new AddressCreate();
		$billingAddress->setStreet($customer->cStrasse);
		$billingAddress->setCity($customer->cOrt);
		$billingAddress->setCountry($customer->cLand);
		$billingAddress->setEmailAddress($customer->cMail);
		$billingAddress->setFamilyName($customer->cNachname);
		$billingAddress->setGivenName($customer->cVorname);
		$billingAddress->setPostCode($customer->cPLZ);
		$billingAddress->setPostalState($customer->cBundesland);
		$billingAddress->setOrganizationName($customer->cFirma);
		$billingAddress->setPhoneNumber($customer->cMobil);
		$billingAddress->setSalutation($customer->cTitel);
		
		return $billingAddress;
	}
	
	/**
	 * @return AddressCreate
	 */
	private function createShippingAddress(): AddressCreate
	{
		$customer = $_SESSION['Lieferadresse'];
		
		$shippingAddress = new AddressCreate();
		$shippingAddress->setStreet($customer->cStrasse);
		$shippingAddress->setCity($customer->cOrt);
		$shippingAddress->setCountry($customer->cLand);
		$shippingAddress->setEmailAddress($customer->cMail);
		$shippingAddress->setFamilyName($customer->cNachname);
		$shippingAddress->setGivenName($customer->cVorname);
		$shippingAddress->setPostCode($customer->cPLZ);
		$shippingAddress->setPostalState($customer->cBundesland);
		$shippingAddress->setOrganizationName($customer->cFirma);
		$shippingAddress->setPhoneNumber($customer->cMobil);
		$shippingAddress->setSalutation($customer->cTitel);
		
		return $shippingAddress;
	}
	
	/**
	 * @param string $transactionId
	 * @param array $orderData
	 * @return void
	 */
	private function createLocalWalleeTransaction(string $transactionId, array $orderData): void
	{
		$newTransaction = new \stdClass();
		$newTransaction->transaction_id = $transactionId;
		$newTransaction->amount = $orderData['fGesamtsumme'];
		$newTransaction->data = json_encode($orderData);
		$newTransaction->payment_method = $orderData['cZahlungsartName'];
		$newTransaction->order_id = $orderData['kBestellung'];
		$newTransaction->space_id = $this->spaceId;
		$newTransaction->state = TransactionState::PENDING;
		$newTransaction->created_at = date('Y-m-d H:i:s');
		
		Shop::Container()->getDB()->insert('wallee_transactions', $newTransaction);
	}
	
	/**
	 * @param string $transactionId
	 * @param array $orderData
	 * @return void
	 */
	private function updateLocalWalleeTransaction(string $transactionId): void
	{
		Shop::Container()
		  ->getDB()->update(
			'wallee_transactions',
			['transaction_id'],
			[$transactionId],
			(object)[
			  'state' => TransactionState::PROCESSING,
			  'payment_method' => $_SESSION['Zahlungsart']->cName,
			  'order_id' => $_SESSION['kBestellung'],
			  'space_id' => $this->spaceId,
			  'created_at' => date('Y-m-d H:i:s')
			]
		  );
	}
}
