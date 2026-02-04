<?php declare(strict_types=1);

namespace Plugin\jtl_wallee;

use JTL\Shop;
use Wallee\Sdk\ApiClient;
use JTL\Plugin\Helper as PluginHelper;

/**
 * Class WalleeApiClient
 * @package Plugin\jtl_wallee
 */
class WalleeApiClient
{
	/**
	 * @var ApiClient $apiClient
	 */
	protected $apiClient;
	
	
	const SHOP_SYSTEM = 'x-meta-shop-system';
	const SHOP_SYSTEM_VERSION = 'x-meta-shop-system-version';
	const SHOP_SYSTEM_AND_VERSION = 'x-meta-shop-system-and-version';
	const PLUGIN_VERSION   = 'x-meta-plugin-version';
	
	public function __construct(int $pluginId)
	{
		if (!$this->getApiClient()) {
			$config = WalleeHelper::getConfigByID($pluginId);
			$userId = $config[WalleeHelper::USER_ID] ?? null;
			$applicationKey = $config[WalleeHelper::APPLICATION_KEY] ?? null;
			$plugin = PluginHelper::getLoaderByPluginID($pluginId)->init($pluginId);
			
			if (empty($userId) || empty($applicationKey)) {
				if (isset($_POST['Setting'])) {
					$translations = WalleeHelper::getTranslations($plugin->getLocalization(), [
					  'jtl_wallee_incorrect_user_id_or_application_key',
					]);
					Shop::Container()->getAlertService()->addDanger(
					  $translations['jtl_wallee_incorrect_user_id_or_application_key'],
					  'getApiClient'
					);
				}
				return null;
			}
			
			try {
				$apiClient = new ApiClient($userId, $applicationKey);
				$apiClientBasePath = getenv('WALLEE_API_BASE_PATH') ? getenv('WALLEE_API_BASE_PATH') : $apiClient->getBasePath();
				$apiClient->setBasePath($apiClientBasePath);
				foreach (self::getDefaultHeaderData() as $key => $value) {
					$apiClient->addDefaultHeader($key, $value);
				}
				$this->apiClient = $apiClient;
			} catch (\Exception $exception) {
				$translations = WalleeHelper::getTranslations($plugin->getLocalization(), [
				  'jtl_wallee_incorrect_user_id_or_application_key',
				]);
				Shop::Container()->getAlertService()->addDanger(
				  $translations['jtl_wallee_incorrect_user_id_or_application_key'],
				  'getApiClient'
				);
				return null;
			}
		}
	}
	
	/**
	 * @return array
	 */
	protected static function getDefaultHeaderData(): array
	{
		$shop_version = APPLICATION_VERSION;
		[$major_version, $minor_version, $_] = explode('.', $shop_version, 3);
		return [
		  self::SHOP_SYSTEM => 'jtl',
		  self::SHOP_SYSTEM_VERSION => $shop_version,
		  self::SHOP_SYSTEM_AND_VERSION => 'jtl-' . $major_version . '.' . $minor_version,
		  self::PLUGIN_VERSION => '1.0.42',
		];
	}
	
	/**
	 * @return ApiClient|null
	 */
	public function getApiClient(): ?ApiClient
	{
		return $this->apiClient;
	}
}

