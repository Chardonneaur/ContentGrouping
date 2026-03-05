<?php

namespace Piwik\Plugins\ContentGrouping;

use Piwik\Updater;
use Piwik\Updates as PiwikUpdates;
use Piwik\Updater\Migration\Factory as MigrationFactory;

class Updates_1_2_0 extends PiwikUpdates
{
    /** @var MigrationFactory */
    private $migration;

    public function __construct(MigrationFactory $factory)
    {
        $this->migration = $factory;
    }

    public function getMigrations(Updater $updater)
    {
        return [
            $this->migration->db->addColumn(
                'content_grouping_rule',
                'mapping_name',
                "VARCHAR(100) NOT NULL DEFAULT 'default'",
                'idsite'
            ),
            $this->migration->db->addIndex(
                'content_grouping_rule',
                ['idsite', 'mapping_name', 'priority'],
                'idx_site_mapping_priority'
            ),
        ];
    }

    public function doUpdate(Updater $updater)
    {
        $updater->executeMigrations(__FILE__, $this->getMigrations($updater));
    }
}
