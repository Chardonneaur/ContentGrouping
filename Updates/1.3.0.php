<?php

namespace Piwik\Plugins\ContentGrouping;

use Piwik\Common;
use Piwik\Updater;
use Piwik\Updates as PiwikUpdates;
use Piwik\Updater\Migration\Factory as MigrationFactory;
use Piwik\Updater\Migration\Db as DbMigration;

class Updates_1_3_0 extends PiwikUpdates
{
    /** @var MigrationFactory */
    private $migration;

    public function __construct(MigrationFactory $factory)
    {
        $this->migration = $factory;
    }

    public function getMigrations(Updater $updater)
    {
        $mappingTable = Common::prefixTable('content_grouping_mapping');
        $rulesTable = Common::prefixTable('content_grouping_rule');

        return [
            $this->migration->db->createTable('content_grouping_mapping', [
                'idmapping' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                'idsite' => 'INT UNSIGNED NOT NULL',
                'mapping_name' => 'VARCHAR(100) NOT NULL',
                'created_date' => 'DATETIME NOT NULL',
                'updated_date' => 'DATETIME NOT NULL',
            ], ['idmapping']),
            $this->migration->db->addUniqueKey(
                'content_grouping_mapping',
                ['idsite', 'mapping_name'],
                'uniq_site_mapping'
            ),
            $this->migration->db->addIndex(
                'content_grouping_mapping',
                ['idsite'],
                'idx_site'
            ),
            $this->migration->db->sql(
                "INSERT IGNORE INTO `{$mappingTable}` (idsite, mapping_name, created_date, updated_date)
                 SELECT DISTINCT idsite, mapping_name, NOW(), NOW()
                 FROM `{$rulesTable}`",
                DbMigration::ERROR_CODE_TABLE_NOT_EXISTS
            ),
        ];
    }

    public function doUpdate(Updater $updater)
    {
        $updater->executeMigrations(__FILE__, $this->getMigrations($updater));
    }
}
