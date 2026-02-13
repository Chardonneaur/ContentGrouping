<?php

namespace Piwik\Plugins\ContentGrouping\Dao;

use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;

class RulesDao
{
    private $table = 'content_grouping_rule';
    private $tablePrefixed;

    public function __construct()
    {
        $this->tablePrefixed = Common::prefixTable($this->table);
    }

    public function install()
    {
        DbHelper::createTable($this->table, "
            `idrule` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `idsite` INT UNSIGNED NOT NULL,
            `group_name` VARCHAR(255) NOT NULL,
            `pattern` VARCHAR(500) NOT NULL,
            `match_type` VARCHAR(10) NOT NULL DEFAULT 'prefix',
            `priority` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_date` DATETIME NOT NULL,
            `updated_date` DATETIME NOT NULL,
            PRIMARY KEY (`idrule`),
            KEY `idx_site_priority` (`idsite`, `priority`)
        ");
    }

    public function uninstall()
    {
        Db::query(sprintf('DROP TABLE IF EXISTS `%s`', $this->tablePrefixed));
    }

    public function getRulesForSite($idSite)
    {
        $sql = "SELECT * FROM {$this->tablePrefixed} WHERE idsite = ? ORDER BY priority ASC, idrule ASC";
        return Db::fetchAll($sql, [(int) $idSite]);
    }

    public function getRule($idRule, $idSite)
    {
        $sql = "SELECT * FROM {$this->tablePrefixed} WHERE idrule = ? AND idsite = ?";
        return Db::fetchRow($sql, [(int) $idRule, (int) $idSite]);
    }

    public function addRule($idSite, $groupName, $pattern, $matchType, $priority = 0)
    {
        $now = date('Y-m-d H:i:s');

        $columns = implode('`,`', ['idsite', 'group_name', 'pattern', 'match_type', 'priority', 'created_date', 'updated_date']);
        $sql = sprintf('INSERT INTO %s (`%s`) VALUES(?,?,?,?,?,?,?)', $this->tablePrefixed, $columns);
        $bind = [(int) $idSite, $groupName, $pattern, $matchType, (int) $priority, $now, $now];

        Db::query($sql, $bind);
        return (int) Db::get()->lastInsertId();
    }

    private const ALLOWED_UPDATE_COLUMNS = ['group_name', 'pattern', 'match_type', 'priority'];

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
    }
}
