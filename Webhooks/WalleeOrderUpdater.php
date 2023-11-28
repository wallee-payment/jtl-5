<?php declare(strict_types=1);

namespace Plugin\jtl_wallee\Webhooks;

use Plugin\jtl_wallee\Webhooks\Strategies\Interfaces\WalleeOrderUpdateStrategyInterface;

class WalleeOrderUpdater
{
	/**
	 * @var WalleeOrderUpdateStrategyInterface $strategy
	 */
	private $strategy;

	public function __construct(WalleeOrderUpdateStrategyInterface $strategy)
	{
		$this->strategy = $strategy;
	}

	/**
	 * @param WalleeOrderUpdateStrategyInterface $strategy
	 * @return void
	 */
	public function setStrategy(WalleeOrderUpdateStrategyInterface $strategy)
	{
		$this->strategy = $strategy;
	}

	/**
	 * @param string $transactionId
	 * @return void
	 */
	public function updateOrderStatus(string $transactionId): void
	{
		$this->strategy->updateOrderStatus($transactionId);
	}
}
