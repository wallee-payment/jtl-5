<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Webhooks\Strategies\Interfaces;

interface WalleeOrderUpdateStrategyInterface
{
	public function updateOrderStatus(string $entityId): void;
}
