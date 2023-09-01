<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Services;

use JTL\Shop;
use Wallee\Sdk\ApiClient;

class WalleeOrderService
{
	public function updateOrderStatus($orderId, $currentStatus, $newStatus)
	{
		return Shop::Container()
		  ->getDB()->update(
			'tbestellung',
			['kBestellung', 'cStatus'],
			[$orderId, $currentStatus],
			(object)['cStatus' => $newStatus]
		  );
	}
}
