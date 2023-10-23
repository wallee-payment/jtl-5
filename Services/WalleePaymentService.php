<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Services;

use JTL\Helpers\PaymentMethod;
use JTL\Shop;
use JTL\Plugin\Helper;
use Plugin\jtl_wallee\WalleeHelper;
use stdClass;
use Wallee\Sdk\ApiClient;
use Wallee\Sdk\Model\CreationEntityState;
use Wallee\Sdk\Model\CriteriaOperator;
use Wallee\Sdk\Model\EntityQuery;
use Wallee\Sdk\Model\EntityQueryFilter;
use Wallee\Sdk\Model\EntityQueryFilterType;
use Wallee\Sdk\Model\PaymentMethodConfiguration;
use JTL\Plugin\Helper as PluginHelper;

/**
 * Class WalleeApiClient
 * @package Plugin\jtl_wallee
 */
class WalleePaymentService
{
	const TABLE_NAME_PAYMENT_METHODS = 'tzahlungsart';
	const TABLE_NAME_PAYMENT_METHOD_LANG = 'tzahlungsartsprache';
	const TABLE_NAME_PAYMENT_METHOD_CLASS = 'tpluginzahlungsartklasse';
	const TABLE_NAME_SHIPPING_ARTS = 'tversandart';
	const TABLE_NAME_SHIPPING_PAYMENT = 'tversandartzahlungsart';
	
	private $localeLanguageMapping = [
	  'de-DE' => 'ger',
	  'fr-FR' => 'fre',
	  'it-IT' => 'ita',
	  'en-US' => 'eng',
	];
	
	/**
	 * @var ApiClient $apiClient
	 */
	protected ApiClient $apiClient;
	
	/**
	 * @var int $pluginId
	 */
	protected int $pluginId;
	
	public function __construct(ApiClient $apiClient, int $pluginId)
	{
		$this->apiClient = $apiClient;
		$this->pluginId = $pluginId;
	}
	
	public function getPaymentMethodConfigurations(): ?array
	{
		$entityQueryFilter = (new EntityQueryFilter())
		  ->setOperator(CriteriaOperator::EQUALS)
		  ->setFieldName('state')
		  ->setType(EntityQueryFilterType::LEAF)
		  ->setValue(CreationEntityState::ACTIVE);
		
		$entityQuery = (new EntityQuery())->setFilter($entityQueryFilter);
		$apiClient = $this->apiClient;
		
		$config = WalleeHelper::getConfigByID($this->pluginId);
		$spaceId = $config[WalleeHelper::SPACE_ID];
		
		if (empty($spaceId)) {
			$translations = WalleeHelper::getTranslations($this->plugin->getLocalization(), [
			  'jtl_wallee_empty_space_id',
			]);
			Shop::Container()->getAlertService()->addDanger(
			  $translations['jtl_wallee_empty_space_id'],
			  'getPaymentMethodConfigurations'
			);
			return [];
		}
		
		try {
			$paymentMethodConfigurations = $apiClient->getPaymentMethodConfigurationService()->search($spaceId, $entityQuery);
		} catch (\Exception $exception) {
			$translations = WalleeHelper::getTranslations($this->plugin->getLocalization(), [
			  'jtl_wallee_cant_fetch_payment_methods',
			]);
			Shop::Container()->getAlertService()->addDanger(
			  $translations['jtl_wallee_cant_fetch_payment_methods'] . ' ' . $exception->getMessage(),
			  'getPaymentMethodConfigurations'
			);
			return [];
		}
		
		usort($paymentMethodConfigurations, function (PaymentMethodConfiguration $item1, PaymentMethodConfiguration $item2) {
			return $item1->getSortOrder() <=> $item2->getSortOrder();
		});
		
		return $paymentMethodConfigurations;
	}
	
	/**
	 * @param string $slug
	 * @param array $data
	 * @param int $sortIndex
	 * @return int|null
	 */
	public function installPaymentMethod(string $slug, array $data, int $sortIndex): ?int
	{
		$paymentMethod = Shop::Container()
		  ->getDB()
		  ->select(self::TABLE_NAME_PAYMENT_METHODS, 'cModulId', $slug);
		
		if ($paymentMethod) {
			return null;
		}
		
		$method = new stdClass();
		$method->cName = $data['module'];
		$method->cModulId = $slug;
		$method->cKundengruppen = '';
		$method->cPluginTemplate = $data['template'] ?? null;
		$method->cZusatzschrittTemplate = $data['additional_template'] ?? null;
		$method->cBild = $data['logo_url'];
		$method->nSort = $sortIndex * (-1);
		$method->nMailSenden = 1;
		$method->nActive = 1;
		$method->cAnbieter = 'Wallee';
		$method->cTSCode = \strtoupper(WalleeHelper::slugify($data['module']));
		$method->nWaehrendBestellung = 0;
		$method->nCURL = 1;
		$method->nSOAP = 0;
		$method->nSOCKETS = 0;
		$method->nNutzbar = 0;
		$methodId = Shop::Container()
		  ->getDB()
		  ->insert(self::TABLE_NAME_PAYMENT_METHODS, $method);
		$method->kZahlungsart = $methodId;
		$method->nNutzbar = PaymentMethod::activatePaymentMethod($method) ? 1 : 0;
		
		return $methodId;
	}
	
	/**
	 * @param array $titles
	 * @param array $descriptions
	 * @param int $paymentMethodID
	 * @return void
	 */
	public function installPaymentMethodTranslations(array $titles, array $descriptions, int $paymentMethodID)
	{
		foreach ($titles as $isoLanguage => $title) {
			$localizedMethod = new stdClass();
			$localizedMethod->kZahlungsart = $paymentMethodID;
			$localizedMethod->cISOSprache = $isoLanguage;
			$localizedMethod->cName = $title;
			$localizedMethod->cGebuehrname = $title;
			$localizedMethod->cHinweisText = $descriptions[$isoLanguage];
			$localizedMethod->cHinweisTextShop = $descriptions[$isoLanguage];
			Shop::Container()
			  ->getDB()
			  ->insert(self::TABLE_NAME_PAYMENT_METHOD_LANG, $localizedMethod);
		}
	}
	
	/**
	 * @param string $pluginId
	 * @param string $slug
	 * @return void
	 */
	public function installPaymentMethodClassFile(string $pluginId, string $slug)
	{
		$paymentClass = new stdClass();
		$paymentClass->cModulId = $pluginId . '_' . $slug;
		$paymentClass->kPlugin = $pluginId;
		$paymentClass->cClassPfad = $data['classFile'] ?? null;
		$paymentClass->cClassName = $data['className'] ?? null;
		$paymentClass->cTemplatePfad = $data['templateFile'] ?? null;
		$paymentClass->cZusatzschrittTemplate = $data['additionalTemplateFile'] ?? null;
		
		Shop::Container()
		  ->getDB()
		  ->insert(self::TABLE_NAME_PAYMENT_METHOD_CLASS, $paymentClass);
	}
	
	public function getInstalledPaymentMethods(): array
	{
		$objects = Shop::Container()
		  ->getDB()
		  ->selectAll(self::TABLE_NAME_PAYMENT_METHODS, 'nActive', 1);
		
		$filteredObjects = array_filter($objects, function ($object) {
			return stripos($object->cAnbieter, 'Wallee') !== false;
		});
		
		$installedPaymentMetodsIds = [];
		foreach ($filteredObjects as $filteredObject) {
			$installedPaymentMetodsIds[] = $filteredObject->cModulId;
		}
		
		return $installedPaymentMetodsIds;
	}
	
	/**
	 * @param array $installedPaymentMethods
	 * @return void
	 */
	public function enablePaymentMethods(array $installedPaymentMethods): void
	{
		$shippingMethods = Shop::Container()->getDB()->getObjects(
		  'SELECT *
                FROM ' . self::TABLE_NAME_SHIPPING_ARTS . '
                ORDER BY kVersandart DESC'
		);
		
		foreach ($shippingMethods as $shippingMethod) {
			foreach ($installedPaymentMethods as $installedPaymentMethodId) {
				$enablePaymentMethod = new stdClass();
				$enablePaymentMethod->kVersandart = $shippingMethod->kVersandart;
				$enablePaymentMethod->kZahlungsart = $installedPaymentMethodId;
				$enablePaymentMethod->fAufpreis = '0.00';
				$enablePaymentMethod->cAufpreisTyp = 'festpreis';
				
				Shop::Container()
				  ->getDB()
				  ->insert(self::TABLE_NAME_SHIPPING_PAYMENT, $enablePaymentMethod);
			}
		}
	}
	
	/**
	 * @return ApiClient|null
	 */
	public function getApiClient(): ?ApiClient
	{
		return $this->apiClient;
	}
	
	/**
	 * @param array $installedPaymentMethodsIds
	 * @param array $paymentMethodsFromPortal
	 * @return void
	 */
	protected function disablePaymentMethods(array $installedPaymentMethodsIds, array $paymentMethodsFromPortal)
	{
		foreach ($installedPaymentMethodsIds as $installedPaymentMethodsId) {
			if (!in_array($installedPaymentMethodsId, $paymentMethodsFromPortal)) {
				$method = new stdClass();
				$method->nActive = 0;
				Shop::Container()->getDB()->update(self::TABLE_NAME_PAYMENT_METHODS, 'cModulId', $installedPaymentMethodsId, $method);
				$check = Shop::Container()->getDB()->selectAll(self::TABLE_NAME_PAYMENT_METHODS, 'cModulId', $installedPaymentMethodsId);
				Shop::Container()->getDB()->delete(self::TABLE_NAME_SHIPPING_PAYMENT, 'kZahlungsart', ($check[0])->kZahlungsart);
			}
		}
	}
	
	public function syncPaymentMethods()
	{
		if (!$this->apiClient) {
			return;
		}
		
		$paymentMethods = $this->getPaymentMethodConfigurations();
		
		if (empty($paymentMethods)) {
			return;
		}
		
		$translations = [];
		
		$installedPaymentMethodsIds = $this->getInstalledPaymentMethods();
		$paymentMethodsFromPortal = [];
		$i = 0;
		/**
		 * PaymentMethodConfiguration $paymentMethod
		 */
		foreach ($paymentMethods as $paymentMethod) {
			$slug = WalleeHelper::PAYMENT_METHOD_PREFIX . '_' . $paymentMethod->getId();
			$paymentMethodsFromPortal[] = $slug;
			
			if (!in_array($slug, $installedPaymentMethodsIds, true)) {
				$check = Shop::Container()->getDB()->selectAll(self::TABLE_NAME_PAYMENT_METHODS, 'cModulId', $slug);
				
				if (!empty($check) && (int)(($check[0])->nActive) === 0) {
					$method = new stdClass();
					$method->nActive = 1;
					Shop::Container()->getDB()->update(self::TABLE_NAME_PAYMENT_METHODS, 'cModulId', $slug, $method);
					$this->enablePaymentMethods([($check[0])->kZahlungsart]);
				} else {
					$descriptions = [];
					$languageMapping = $this->localeLanguageMapping;
					foreach ($paymentMethod->getResolvedDescription() as $locale => $text) {
						$language = $languageMapping[$locale];
						$descriptions[$language] = $translations[$language][$slug . '_description'] = addslashes($text);
					}
					
					$titles = [];
					foreach ($paymentMethod->getResolvedTitle() as $locale => $text) {
						$language = $languageMapping[$locale];
						$titles[$language] = $translations[$language][$slug . '_title'] = addslashes(str_replace('-/', ' / ', $text));
					}
					
					$installedPaymentMethods = [];
					
					$paymentMethod = [
					  'state' => (string)$paymentMethod->getState(),
					  'logo_url' => $paymentMethod->getResolvedImageUrl(),
					  'logo_alt' => $slug,
					  'id' => $slug,
					  'module' => $translations['eng'][$slug . '_title'],
					  'description' => $translations['eng'][$slug . '_description'],
					  'fields' => [],
					  'titles' => $titles,
					  'descriptions' => $descriptions
					];
					
					$paymentMethodId = $this->installPaymentMethod($slug, $paymentMethod, $i);
					if ($paymentMethodId) {
						$installedPaymentMethods[] = $paymentMethodId;
						$this->installPaymentMethodTranslations($titles, $descriptions, $paymentMethodId);
						$this->installPaymentMethodClassFile((string)$this->pluginId, $slug);
						$this->enablePaymentMethods($installedPaymentMethods);
						$i++;
					}
				}
			}
		}
		
		$this->disablePaymentMethods($installedPaymentMethodsIds, $paymentMethodsFromPortal);
		
		$plugin = PluginHelper::getLoaderByPluginID($this->pluginId)->init($this->pluginId);
		$translations = WalleeHelper::getTranslations($plugin->getLocalization(), [
		  'jtl_wallee_payment_methods_were_synchronised',
		]);
		Shop::Container()->getAlertService()->addSuccess(
		  $translations['jtl_wallee_payment_methods_were_synchronised'],
		  'syncPaymentMethods'
		);
	}
}

