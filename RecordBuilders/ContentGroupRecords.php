<?php

namespace Piwik\Plugins\ContentGrouping\RecordBuilders;

use Piwik\ArchiveProcessor;
use Piwik\ArchiveProcessor\Record;
use Piwik\ArchiveProcessor\RecordBuilder;
use Piwik\DataAccess\LogAggregator;
use Piwik\DataTable;
use Piwik\Metrics;
use Piwik\Plugins\Actions\ArchivingHelper;
use Piwik\Plugins\ContentGrouping\Archiver;
use Piwik\Plugins\ContentGrouping\Dao\RulesDao;
use Piwik\Plugins\ContentGrouping\Model\RuleEngine;
use Piwik\Tracker\Action;

class ContentGroupRecords extends RecordBuilder
{
    public function __construct()
    {
        parent::__construct();

        $this->maxRowsInTable = ArchivingHelper::$maximumRowsInDataTableLevelZero;
        $this->maxRowsInSubtable = ArchivingHelper::$maximumRowsInSubDataTable;
        $this->columnToSortByBeforeTruncation = Metrics::INDEX_NB_VISITS;
    }

    public function getRecordMetadata(ArchiveProcessor $archiveProcessor): array
    {
        return [
            Record::make(Record::TYPE_BLOB, Archiver::CONTENT_GROUPS_RECORD_NAME),
        ];
    }

    public function isEnabled(ArchiveProcessor $archiveProcessor): bool
    {
        $idSite = $archiveProcessor->getParams()->getSite()->getId();
        $dao = new RulesDao();
        $rules = $dao->getRulesForSite($idSite);
        return !empty($rules);
    }

    protected function aggregate(ArchiveProcessor $archiveProcessor): array
    {
        $idSite = $archiveProcessor->getParams()->getSite()->getId();
        $dao = new RulesDao();
        $rules = $dao->getRulesForSite($idSite);

        $report = new DataTable();

        if (empty($rules)) {
            return [Archiver::CONTENT_GROUPS_RECORD_NAME => $report];
        }

        $logAggregator = $archiveProcessor->getLogAggregator();
        $ruleEngine = new RuleEngine();

        $this->aggregatePageMetrics($report, $logAggregator, $ruleEngine, $rules);
        $this->aggregateGoalMetrics($report, $logAggregator, $ruleEngine, $rules);

        return [Archiver::CONTENT_GROUPS_RECORD_NAME => $report];
    }

    private function aggregatePageMetrics(DataTable $report, LogAggregator $logAggregator, RuleEngine $ruleEngine, array $rules)
    {
        $select = "
            log_action.idaction AS idaction,
            log_action.name AS url,
            count(distinct log_link_visit_action.idvisit) AS `" . Metrics::INDEX_NB_VISITS . "`,
            count(distinct log_link_visit_action.idvisitor) AS `" . Metrics::INDEX_NB_UNIQ_VISITORS . "`,
            count(*) AS `" . Metrics::INDEX_PAGE_NB_HITS . "`,
            sum(log_link_visit_action.time_spent) AS `" . Metrics::INDEX_PAGE_SUM_TIME_SPENT . "`,
            sum(case when log_link_visit_action.idaction_url_ref = 0 then 1 else 0 end) AS `" . Metrics::INDEX_PAGE_ENTRY_NB_VISITS . "`,
            sum(case log_visit.visit_total_actions when 1 then 1 else 0 end) AS `" . Metrics::INDEX_BOUNCE_COUNT . "`,
            sum(case when log_link_visit_action.idaction_url_ref != 0
                     and log_link_visit_action.idaction_url != ifnull(
                         (select la2.idaction_url from " . \Piwik\Common::prefixTable('log_link_visit_action') . " la2
                          where la2.idvisit = log_link_visit_action.idvisit
                            and la2.idlink_va > log_link_visit_action.idlink_va
                          order by la2.idlink_va asc limit 1), 0)
                     then 0
                     when log_link_visit_action.idaction_url_ref != 0 then 1
                     else 0 end) AS `" . Metrics::INDEX_PAGE_EXIT_NB_VISITS . "`
        ";

        $from = [
            'log_link_visit_action',
            [
                'table' => 'log_action',
                'joinOn' => 'log_link_visit_action.idaction_url = log_action.idaction',
            ],
            [
                'table' => 'log_visit',
                'joinOn' => 'log_visit.idvisit = log_link_visit_action.idvisit',
            ],
        ];

        $where = $logAggregator->getWhereStatement('log_link_visit_action', 'server_time');
        $where .= sprintf(' AND log_action.type = %d', Action::TYPE_PAGE_URL);

        $groupBy = 'log_action.idaction';
        $orderBy = '`' . Metrics::INDEX_NB_VISITS . '` DESC';

        $query = $logAggregator->generateQuery($select, $from, $where, $groupBy, $orderBy);
        $resultSet = $logAggregator->getDb()->query($query['sql'], $query['bind']);

        $groupIdactions = [];

        while ($row = $resultSet->fetch()) {
            $url = $row['url'];
            if (empty($url)) {
                continue;
            }

            $groupName = $ruleEngine->evaluateUrl($url, $rules);
            $groupIdactions[$groupName][] = (int) $row['idaction'];

            $columns = [
                Metrics::INDEX_NB_VISITS => (int) $row[Metrics::INDEX_NB_VISITS],
                Metrics::INDEX_NB_UNIQ_VISITORS => (int) $row[Metrics::INDEX_NB_UNIQ_VISITORS],
                Metrics::INDEX_PAGE_NB_HITS => (int) $row[Metrics::INDEX_PAGE_NB_HITS],
                Metrics::INDEX_PAGE_SUM_TIME_SPENT => (int) $row[Metrics::INDEX_PAGE_SUM_TIME_SPENT],
                Metrics::INDEX_PAGE_ENTRY_NB_VISITS => (int) $row[Metrics::INDEX_PAGE_ENTRY_NB_VISITS],
                Metrics::INDEX_BOUNCE_COUNT => (int) $row[Metrics::INDEX_BOUNCE_COUNT],
                Metrics::INDEX_PAGE_EXIT_NB_VISITS => (int) $row[Metrics::INDEX_PAGE_EXIT_NB_VISITS],
            ];

            $topLevelRow = $report->sumRowWithLabel($groupName, $columns);
            $topLevelRow->sumRowWithLabelToSubtable($url, $columns);
        }

        // Fix non-additive metrics (visits, unique visitors) at the group level.
        // COUNT(DISTINCT) values are not additive across URLs, so we must re-query
        // with GROUP BY content_group to get the correct distinct counts.
        $this->correctGroupDistinctMetrics($report, $logAggregator, $groupIdactions);
    }

    /**
     * Re-compute COUNT(DISTINCT idvisit) and COUNT(DISTINCT idvisitor) at the
     * content-group level so that visits spanning multiple URLs within one group
     * are counted only once.
     */
    private function correctGroupDistinctMetrics(DataTable $report, LogAggregator $logAggregator, array $groupIdactions)
    {
        if (empty($groupIdactions)) {
            return;
        }

        $db = $logAggregator->getDb();

        $caseParts = [];
        foreach ($groupIdactions as $groupName => $idactions) {
            $ids = implode(',', $idactions);
            $caseParts[] = sprintf('WHEN log_action.idaction IN (%s) THEN %s', $ids, $db->quote($groupName));
        }
        $caseExpr = 'CASE ' . implode(' ', $caseParts) . ' END';

        $select = "
            $caseExpr AS content_group,
            COUNT(DISTINCT log_link_visit_action.idvisit) AS `" . Metrics::INDEX_NB_VISITS . "`,
            COUNT(DISTINCT log_link_visit_action.idvisitor) AS `" . Metrics::INDEX_NB_UNIQ_VISITORS . "`
        ";

        $from = [
            'log_link_visit_action',
            [
                'table' => 'log_action',
                'joinOn' => 'log_link_visit_action.idaction_url = log_action.idaction',
            ],
        ];

        $where = $logAggregator->getWhereStatement('log_link_visit_action', 'server_time');
        $where .= sprintf(' AND log_action.type = %d', Action::TYPE_PAGE_URL);

        $groupBy = 'content_group';

        $query = $logAggregator->generateQuery($select, $from, $where, $groupBy, false);
        $resultSet = $db->query($query['sql'], $query['bind']);

        while ($row = $resultSet->fetch()) {
            $groupName = $row['content_group'];
            if (empty($groupName)) {
                continue;
            }

            $existingRow = $report->getRowFromLabel($groupName);
            if ($existingRow) {
                $existingRow->setColumn(Metrics::INDEX_NB_VISITS, (int) $row[Metrics::INDEX_NB_VISITS]);
                $existingRow->setColumn(Metrics::INDEX_NB_UNIQ_VISITORS, (int) $row[Metrics::INDEX_NB_UNIQ_VISITORS]);
            }
        }
    }

    private function aggregateGoalMetrics(DataTable $report, LogAggregator $logAggregator, RuleEngine $ruleEngine, array $rules)
    {
        $select = "
            lac.name AS url,
            log_conversion.idgoal AS idgoal,
            COUNT(*) AS `" . Metrics::INDEX_GOAL_NB_CONVERSIONS . "`,
            COUNT(DISTINCT log_conversion.idvisit) AS `" . Metrics::INDEX_GOAL_NB_VISITS_CONVERTED . "`,
            ROUND(SUM(log_conversion.revenue), 2) AS `" . Metrics::INDEX_GOAL_REVENUE . "`
        ";

        $from = [
            'log_conversion',
            [
                'table' => 'log_link_visit_action',
                'tableAlias' => 'logva',
                'join' => 'RIGHT JOIN',
                'joinOn' => 'log_conversion.idvisit = logva.idvisit',
            ],
            [
                'table' => 'log_action',
                'tableAlias' => 'lac',
                'joinOn' => 'logva.idaction_url = lac.idaction',
            ],
        ];

        $where = $logAggregator->getWhereStatement('log_conversion', 'server_time');
        $where .= sprintf(
            ' AND logva.server_time <= log_conversion.server_time AND lac.type = %d',
            Action::TYPE_PAGE_URL
        );

        $groupBy = 'lac.idaction, log_conversion.idgoal';

        $query = $logAggregator->generateQuery($select, $from, $where, $groupBy, false);
        $resultSet = $logAggregator->getDb()->query($query['sql'], $query['bind']);

        while ($row = $resultSet->fetch()) {
            $url = $row['url'];
            if (empty($url)) {
                continue;
            }

            $groupName = $ruleEngine->evaluateUrl($url, $rules);
            $existingRow = $report->getRowFromLabel($groupName);

            if (!$existingRow) {
                continue;
            }

            $idGoal = (int) $row['idgoal'];
            $goalColumns = Metrics::makeGoalColumnsRow($idGoal, $row);

            $existingGoals = $existingRow->getColumn(Metrics::INDEX_GOALS) ?: [];
            if (isset($existingGoals[$idGoal])) {
                foreach ($goalColumns as $metricId => $value) {
                    $existingGoals[$idGoal][$metricId] = ($existingGoals[$idGoal][$metricId] ?? 0) + $value;
                }
            } else {
                $existingGoals[$idGoal] = $goalColumns;
            }

            $existingRow->setColumn(Metrics::INDEX_GOALS, $existingGoals);
        }
    }
}
