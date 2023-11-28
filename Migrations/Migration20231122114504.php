<?php

namespace Plugin\jtl_wallee\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

class Migration20231122114504 extends Migration implements IMigration
{
    public function up()
    {
        $this->execute("ALTER TABLE `wallee_transactions` DROP COLUMN `amount`;");
    }

    public function down()
    {
        $this->execute("ALTER TABLE `wallee_transactions`
                    ADD COLUMN `amount` double NOT NULL AFTER `order_id`;");
    }
}
