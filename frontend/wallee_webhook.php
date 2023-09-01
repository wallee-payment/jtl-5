<?php declare(strict_types=1);

use Plugin\jtl_wallee\Webhooks\WalleeWebhookManager;

/** @global JTL\Plugin\PluginInterface $plugin */
$webhookManager = new WalleeWebhookManager($plugin);
$webhookManager->listenForWebhooks();
exit;
