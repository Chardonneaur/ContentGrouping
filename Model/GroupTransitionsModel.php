<?php

namespace Piwik\Plugins\ContentGrouping\Model;

use Piwik\Common;
use Piwik\Db;
use Piwik\Segment;

class GroupTransitionsModel
{
    private RuleEngine $ruleEngine;

    public function __construct(RuleEngine $ruleEngine)
    {
        $this->ruleEngine = $ruleEngine;
    }

    /**
     * Build a SQL WHERE condition fragment (and its bind values) that matches
     * the log_action row (aliased $tableAlias) against all rules for a given group.
     *
     * Prefix rules  → LIKE 'pattern%'
     * Regex rules   → REGEXP 'pattern'  (MySQL POSIX ERE, compatible with PHP PCRE for common patterns)
     *
     * Returns [$sqlFragment, $binds].
     */
    private function buildGroupCondition(string $tableAlias, array $groupRules): array
    {
        $conditions = [];
        $binds      = [];

        foreach ($groupRules as $rule) {
            if ($rule['match_type'] === 'prefix') {
                $escaped      = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $rule['pattern']);
                $conditions[] = "`{$tableAlias}`.`name` LIKE ?";
                $binds[]      = $escaped . '%';
            } elseif ($rule['match_type'] === 'regex') {
                $conditions[] = "`{$tableAlias}`.`name` REGEXP ?";
                $binds[]      = $rule['pattern'];
            }
        }

        if (empty($conditions)) {
            return ['(1 = 0)', []];
        }

        return ['(' . implode(' OR ', $conditions) . ')', $binds];
    }

    /**
     * Build a segment subquery fragment for filtering visits by segment.
     * Returns ['sql' => '...', 'bind' => [...]] or null if no segment active.
     */
    private function buildSegmentSubquery(string $segment, int $idSite): ?array
    {
        if ($segment === '') {
            return null;
        }

        $segObj = new Segment($segment, [$idSite]);
        $query  = $segObj->getSelectQuery(
            'log_visit.idvisit',
            'log_visit',
            'log_visit.idsite = ' . $idSite,
            []
        );

        return $query ?: null;
    }

    /**
     * Return the raw page URLs that immediately preceded pages in $groupRules,
     * along with their transition counts.
     *
     * @return array<array{url: string, nb_transitions: string}>
     */
    public function queryPreviousUrls(
        int    $idSite,
        array  $groupRules,
        string $dateStart,
        string $dateEnd,
        int    $limit = 300,
        string $segment = ''
    ): array {
        [$cond, $binds] = $this->buildGroupCondition('la_curr', $groupRules);

        $lva = Common::prefixTable('log_link_visit_action');
        $la  = Common::prefixTable('log_action');
        $lv  = Common::prefixTable('log_visit');

        $where  = "lv.`idsite` = ? AND lva.`server_time` >= ? AND lva.`server_time` <= ? AND {$cond}";
        $params = array_merge([$idSite, $dateStart, $dateEnd], $binds);

        $segSubquery = $this->buildSegmentSubquery($segment, $idSite);
        if ($segSubquery) {
            $where  .= " AND lv.`idvisit` IN ({$segSubquery['sql']})";
            $params  = array_merge($params, $segSubquery['bind']);
        }

        return Db::fetchAll("
            SELECT la_prev.`name` AS url, COUNT(*) AS nb_transitions
            FROM `{$lva}` lva
            INNER JOIN `{$lv}` lv
                ON lv.`idvisit` = lva.`idvisit`
            INNER JOIN `{$la}` la_curr
                ON la_curr.`idaction` = lva.`idaction_url`
               AND la_curr.`type` = 1
            INNER JOIN `{$la}` la_prev
                ON la_prev.`idaction` = lva.`idaction_url_ref`
               AND la_prev.`type` = 1
            WHERE {$where}
            GROUP BY la_prev.`name`
            ORDER BY nb_transitions DESC
            LIMIT {$limit}
        ", $params);
    }

    /**
     * Return the raw page URLs that immediately followed pages in $groupRules,
     * along with their transition counts.
     *
     * @return array<array{url: string, nb_transitions: string}>
     */
    public function queryFollowingUrls(
        int    $idSite,
        array  $groupRules,
        string $dateStart,
        string $dateEnd,
        int    $limit = 300,
        string $segment = ''
    ): array {
        [$cond, $binds] = $this->buildGroupCondition('la_grp', $groupRules);

        $lva = Common::prefixTable('log_link_visit_action');
        $la  = Common::prefixTable('log_action');
        $lv  = Common::prefixTable('log_visit');

        $where  = "lv.`idsite` = ? AND lva.`server_time` >= ? AND lva.`server_time` <= ? AND {$cond}";
        $params = array_merge([$idSite, $dateStart, $dateEnd], $binds);

        $segSubquery = $this->buildSegmentSubquery($segment, $idSite);
        if ($segSubquery) {
            $where  .= " AND lv.`idvisit` IN ({$segSubquery['sql']})";
            $params  = array_merge($params, $segSubquery['bind']);
        }

        return Db::fetchAll("
            SELECT la_next.`name` AS url, COUNT(*) AS nb_transitions
            FROM `{$lva}` lva
            INNER JOIN `{$lv}` lv
                ON lv.`idvisit` = lva.`idvisit`
            INNER JOIN `{$la}` la_grp
                ON la_grp.`idaction` = lva.`idaction_url_ref`
               AND la_grp.`type` = 1
            INNER JOIN `{$la}` la_next
                ON la_next.`idaction` = lva.`idaction_url`
               AND la_next.`type` = 1
            WHERE {$where}
            GROUP BY la_next.`name`
            ORDER BY nb_transitions DESC
            LIMIT {$limit}
        ", $params);
    }

    /**
     * Count total page views for pages matching $groupRules in the given period.
     */
    public function countPageviews(
        int    $idSite,
        array  $groupRules,
        string $dateStart,
        string $dateEnd,
        string $segment = ''
    ): int {
        [$cond, $binds] = $this->buildGroupCondition('la', $groupRules);

        $lva = Common::prefixTable('log_link_visit_action');
        $la  = Common::prefixTable('log_action');
        $lv  = Common::prefixTable('log_visit');

        $where  = "lv.`idsite` = ? AND lva.`server_time` >= ? AND lva.`server_time` <= ? AND {$cond}";
        $params = array_merge([$idSite, $dateStart, $dateEnd], $binds);

        $segSubquery = $this->buildSegmentSubquery($segment, $idSite);
        if ($segSubquery) {
            $where  .= " AND lv.`idvisit` IN ({$segSubquery['sql']})";
            $params  = array_merge($params, $segSubquery['bind']);
        }

        return (int) Db::fetchOne("
            SELECT COUNT(*)
            FROM `{$lva}` lva
            INNER JOIN `{$lv}` lv
                ON lv.`idvisit` = lva.`idvisit`
            INNER JOIN `{$la}` la
                ON la.`idaction` = lva.`idaction_url`
               AND la.`type` = 1
            WHERE {$where}
        ", $params);
    }

    /**
     * Classify raw URL rows into content groups using the rule engine and
     * aggregate transition counts per group.
     *
     * Returns [{label: string, nb: int}] sorted descending.
     *
     * @param  array<array{url: string, nb_transitions: string}> $urlRows
     * @param  array $allRules  All rules for the mapping (priority-ordered)
     * @return array<array{label: string, nb: int}>
     */
    public function classifyAndAggregate(array $urlRows, array $allRules): array
    {
        $groups = [];
        foreach ($urlRows as $row) {
            $group          = $this->ruleEngine->evaluateUrl($row['url'], $allRules);
            $nb             = (int) $row['nb_transitions'];
            $groups[$group] = ($groups[$group] ?? 0) + $nb;
        }

        arsort($groups);

        $result = [];
        foreach ($groups as $label => $nb) {
            $result[] = ['label' => $label, 'nb' => $nb];
        }
        return $result;
    }
}
