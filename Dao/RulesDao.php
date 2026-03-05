<?php

namespace Piwik\Plugins\ContentGrouping\Dao;

use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;

class RulesDao
{
    private $table = 'content_grouping_rule';
    private $mappingTable = 'content_grouping_mapping';
    private $tablePrefixed;
    private $mappingTablePrefixed;

    public function __construct()
    {
        $this->tablePrefixed = Common::prefixTable($this->table);
        $this->mappingTablePrefixed = Common::prefixTable($this->mappingTable);
    }

    public function install()
    {
        DbHelper::createTable($this->mappingTable, "
            `idmapping` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `idsite` INT UNSIGNED NOT NULL,
            `mapping_name` VARCHAR(100) NOT NULL,
            `created_date` DATETIME NOT NULL,
            `updated_date` DATETIME NOT NULL,
            PRIMARY KEY (`idmapping`),
            UNIQUE KEY `uniq_site_mapping` (`idsite`, `mapping_name`),
            KEY `idx_site` (`idsite`)
        ");

        DbHelper::createTable($this->table, "
            `idrule` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `idsite` INT UNSIGNED NOT NULL,
            `mapping_name` VARCHAR(100) NOT NULL DEFAULT 'default',
            `group_name` VARCHAR(255) NOT NULL,
            `pattern` VARCHAR(500) NOT NULL,
            `match_type` VARCHAR(10) NOT NULL DEFAULT 'prefix',
            `priority` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_date` DATETIME NOT NULL,
            `updated_date` DATETIME NOT NULL,
            PRIMARY KEY (`idrule`),
            KEY `idx_site_priority` (`idsite`, `priority`),
            KEY `idx_site_mapping_priority` (`idsite`, `mapping_name`, `priority`)
        ");
    }

    public function uninstall()
    {
        Db::query(sprintf('DROP TABLE IF EXISTS `%s`', $this->tablePrefixed));
        Db::query(sprintf('DROP TABLE IF EXISTS `%s`', $this->mappingTablePrefixed));
    }

    public function getRulesForSite($idSite, $mappingName = 'default')
    {
        if ($mappingName === null) {
            $sql = "SELECT * FROM {$this->tablePrefixed} WHERE idsite = ? ORDER BY mapping_name ASC, priority ASC, idrule ASC";
            return Db::fetchAll($sql, [(int) $idSite]);
        }

        $sql = "SELECT * FROM {$this->tablePrefixed} WHERE idsite = ? AND mapping_name = ? ORDER BY priority ASC, idrule ASC";
        return Db::fetchAll($sql, [(int) $idSite, (string) $mappingName]);
    }

    public function getMappingsForSite($idSite): array
    {
        try {
            $sql = "SELECT mapping_name FROM {$this->mappingTablePrefixed} WHERE idsite = ?
                    UNION
                    SELECT DISTINCT mapping_name FROM {$this->tablePrefixed} WHERE idsite = ?
                    ORDER BY mapping_name ASC";
            $rows = Db::fetchAll($sql, [(int) $idSite, (int) $idSite]);
        } catch (\Exception $e) {
            // Fallback for runtimes where the mapping table migration has not been executed yet.
            $sql = "SELECT DISTINCT mapping_name FROM {$this->tablePrefixed} WHERE idsite = ? ORDER BY mapping_name ASC";
            $rows = Db::fetchAll($sql, [(int) $idSite]);
        }

        return array_map(function ($row) {
            return $row['mapping_name'];
        }, $rows);
    }

    public function addMapping($idSite, $mappingName): void
    {
        $now = date('Y-m-d H:i:s');

        try {
            $sql = "INSERT INTO {$this->mappingTablePrefixed} (idsite, mapping_name, created_date, updated_date)
                    VALUES (?, ?, ?, ?)";
            Db::query($sql, [(int) $idSite, (string) $mappingName, $now, $now]);
        } catch (\Exception $e) {
            // likely duplicate key, refresh timestamp instead
            $sql = "UPDATE {$this->mappingTablePrefixed}
                    SET updated_date = ?
                    WHERE idsite = ? AND mapping_name = ?";
            Db::query($sql, [$now, (int) $idSite, (string) $mappingName]);
        }
    }

    public function getRule($idRule, $idSite)
    {
        $sql = "SELECT * FROM {$this->tablePrefixed} WHERE idrule = ? AND idsite = ?";
        return Db::fetchRow($sql, [(int) $idRule, (int) $idSite]);
    }

    public function addRule($idSite, $groupName, $pattern, $matchType, $priority = 0, $mappingName = 'default')
    {
        $now = date('Y-m-d H:i:s');
        $this->addMapping($idSite, $mappingName);

        $columns = implode('`,`', ['idsite', 'mapping_name', 'group_name', 'pattern', 'match_type', 'priority', 'created_date', 'updated_date']);
        $sql = sprintf('INSERT INTO %s (`%s`) VALUES(?,?,?,?,?,?,?,?)', $this->tablePrefixed, $columns);
        $bind = [(int) $idSite, $mappingName, $groupName, $pattern, $matchType, (int) $priority, $now, $now];

        Db::query($sql, $bind);
        return (int) Db::get()->lastInsertId();
    }

    private const ALLOWED_UPDATE_COLUMNS = ['mapping_name', 'group_name', 'pattern', 'match_type', 'priority'];

    public function updateRule($idRule, $idSite, $columns)
    {
        $columns = array_intersect_key($columns, array_flip(self::ALLOWED_UPDATE_COLUMNS));

        if (empty($columns)) {
            return;
        }

        $columns['updated_date'] = date('Y-m-d H:i:s');

        $fields = [];
        $bind = [];
        foreach ($columns as $key => $value) {
            $fields[] = "`$key` = ?";
            $bind[] = $value;
        }

        $bind[] = (int) $idRule;
        $bind[] = (int) $idSite;

        $sql = sprintf('UPDATE %s SET %s WHERE idrule = ? AND idsite = ?', $this->tablePrefixed, implode(', ', $fields));
        Db::query($sql, $bind);
    }

    public function deleteRule($idRule, $idSite)
    {
        $sql = sprintf('DELETE FROM %s WHERE idrule = ? AND idsite = ?', $this->tablePrefixed);
        Db::query($sql, [(int) $idRule, (int) $idSite]);
    }

    public function deleteRulesForSite($idSite)
    {
        $sql = sprintf('DELETE FROM %s WHERE idsite = ?', $this->tablePrefixed);
        Db::query($sql, [(int) $idSite]);

        $sql = sprintf('DELETE FROM %s WHERE idsite = ?', $this->mappingTablePrefixed);
        Db::query($sql, [(int) $idSite]);
    }
}
