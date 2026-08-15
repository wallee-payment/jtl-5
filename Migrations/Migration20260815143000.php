<?php

declare(strict_types=1);

namespace Plugin\jtl_wallee\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

class Migration20260815143000 extends Migration implements IMigration
{
    protected $description = 'Add wawi sync release marker to the wallee_transactions table';

    /**
     * @inheritdoc
     */
    public function up()
    {
        $this->execute("ALTER TABLE `wallee_transactions`
            ADD COLUMN `wawi_sync_released` tinyint(1) NOT NULL DEFAULT '0'
            AFTER `cancellation_email_sent`;");
    }

    /**
     * @inheritdoc
     */
    public function down()
    {
        $this->execute("ALTER TABLE `wallee_transactions` DROP COLUMN `wawi_sync_released`;");
    }
}
