<?php

namespace Piwik\Plugins\ContentGrouping;

use Piwik\Archive;
use Piwik\Archive\ArchiveInvalidator;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\Date;
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Piwik;
use Piwik\Site;
use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\ContentGrouping\Dao\RulesDao;
use Piwik\Plugins\ContentGrouping\Model\GroupTransitionsModel;
use Piwik\Plugins\ContentGrouping\Model\RuleEngine;

/**
 * @method static API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    // ---- Report ----

    public function getContentGroups($idSite, $period, $date, $segment = false, $idSubtable = false, $mappingName = Archiver::DEFAULT_MAPPING_NAME)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $mappingName = $this->normalizeMappingName($mappingName);
        $recordName = Archiver::getRecordNameForMapping($mappingName);

        $dataTable = Archive::createDataTableFromArchive(
            $recordName,
            $idSite,
            $period,
            $date,
            $segment,
            $expanded = false,
            $flat = false,
            $idSubtable
        );

        $dataTable->queueFilter('ReplaceColumnNames');
        $dataTable->queueFilter('ReplaceSummaryRowLabel');

        return $dataTable;
    }

    // ---- Group Transitions ----

    public function getGroupTransitions(
        $idSite,
        $period,
        $date,
        $groupName,
        $mappingName = Archiver::DEFAULT_MAPPING_NAME,
        $segment = false
    ): array {
        Piwik::checkUserHasViewAccess($idSite);

        $mappingName = $this->normalizeMappingName($mappingName);
        $groupName   = trim((string) $groupName);
        $segment     = (string) ($segment ?: '');

        $periodObj = PeriodFactory::build($period, $date);
        $start     = $periodObj->getDateTimeStart()->toString('Y-m-d H:i:s');
        $end       = $periodObj->getDateTimeEnd()->toString('Y-m-d H:i:s');

        $dao        = new RulesDao();
        $allRules   = $dao->getRulesForSite($idSite, $mappingName);
        $groupRules = array_values(array_filter($allRules, fn($r) => $r['group_name'] === $groupName));

        if (empty($groupRules)) {
            return [
                'groupName'       => $groupName,
                'mappingName'     => $mappingName,
                'pageviews'       => 0,
                'previousGroups'  => [],
                'followingGroups' => [],
            ];
        }

        $model = new GroupTransitionsModel(new RuleEngine());

        return [
            'groupName'       => $groupName,
            'mappingName'     => $mappingName,
            'pageviews'       => $model->countPageviews((int) $idSite, $groupRules, $start, $end, $segment),
            'previousGroups'  => $model->classifyAndAggregate(
                $model->queryPreviousUrls((int) $idSite, $groupRules, $start, $end, 300, $segment),
                $allRules
            ),
            'followingGroups' => $model->classifyAndAggregate(
                $model->queryFollowingUrls((int) $idSite, $groupRules, $start, $end, 300, $segment),
                $allRules
            ),
        ];
    }

    // ---- Invalidation ----

    public function invalidateReports($idSite)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $idSite = (int) $idSite;
        $creationDate = Site::getCreationDateFor($idSite);
        $startDate = Date::factory($creationDate);
        $endDate = Date::today();

        // Generate first-of-month dates from creation to today. Using month
        // granularity with cascadeDown keeps the date array small while still
        // invalidating every day, week, month, and year period.
        $dates = [];
        $current = $startDate->setDay(1);
        while ($current->isEarlier($endDate) || $current->toString('Y-m') === $endDate->toString('Y-m')) {
            $dates[] = $current;
            $current = $current->addPeriod(1, 'month');
        }

        if (empty($dates)) {
            return ['success' => true, 'message' => 'No dates to invalidate.'];
        }

        /** @var ArchiveInvalidator $invalidator */
        $invalidator = StaticContainer::get(ArchiveInvalidator::class);
        $result = $invalidator->markArchivesAsInvalidated(
            [$idSite],
            $dates,
            'month',
            null,
            true,
            false,
            'ContentGrouping'
        );

        return [
            'success' => true,
            'message' => Piwik::translate('ContentGrouping_InvalidateSuccess'),
        ];
    }

    // ---- Rules CRUD ----

    public function getRules($idSite, $mappingName = Archiver::DEFAULT_MAPPING_NAME)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $dao = new RulesDao();
        return $dao->getRulesForSite($idSite, $this->normalizeMappingName($mappingName));
    }

    public function getMappings($idSite)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $dao = new RulesDao();
        $mappings = $dao->getMappingsForSite($idSite);
        if (!in_array(Archiver::DEFAULT_MAPPING_NAME, $mappings, true)) {
            $mappings[] = Archiver::DEFAULT_MAPPING_NAME;
            sort($mappings);
        }
        return $mappings;
    }

    public function createMapping($idSite, $mappingName)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $mappingName = $this->normalizeMappingName($mappingName);
        $dao = new RulesDao();
        $dao->addMapping($idSite, $mappingName);

        return [
            'success' => true,
            'mapping' => $mappingName,
        ];
    }

    public function addRule($idSite, $groupName, $pattern, $matchType = 'prefix', $priority = 0, $mappingName = Archiver::DEFAULT_MAPPING_NAME)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $mappingName = $this->normalizeMappingName($mappingName);
        $this->validateRule($groupName, $pattern, $matchType);
        $this->validateNoConflicts($idSite, $mappingName, $groupName, $pattern, $matchType);

        $dao = new RulesDao();
        return $dao->addRule($idSite, $groupName, $pattern, $matchType, (int) $priority, $mappingName);
    }

    public function updateRule($idSite, $idRule, $groupName, $pattern, $matchType = 'prefix', $priority = 0, $mappingName = Archiver::DEFAULT_MAPPING_NAME)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $mappingName = $this->normalizeMappingName($mappingName);
        $this->validateRule($groupName, $pattern, $matchType);

        $dao = new RulesDao();
        $existing = $dao->getRule($idRule, $idSite);
        if (empty($existing)) {
            throw new \Exception('Rule not found.');
        }

        $this->validateNoConflicts($idSite, $mappingName, $groupName, $pattern, $matchType, (int) $idRule);

        $dao->updateRule($idRule, $idSite, [
            'mapping_name' => $mappingName,
            'group_name' => $groupName,
            'pattern' => $pattern,
            'match_type' => $matchType,
            'priority' => (int) $priority,
        ]);
    }

    public function deleteRule($idSite, $idRule)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $dao = new RulesDao();
        $dao->deleteRule($idRule, $idSite);
    }

    public function testUrl($idSite, $url, $mappingName = Archiver::DEFAULT_MAPPING_NAME)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        if (mb_strlen($url) > 2048) {
            throw new \Exception('URL must be 2048 characters or less.');
        }

        $dao = new RulesDao();
        $rules = $dao->getRulesForSite($idSite, $this->normalizeMappingName($mappingName));

        $engine = new RuleEngine();
        return ['group' => $engine->evaluateUrl($url, $rules)];
    }

    public function previewUnmappedPages($idSite, $period, $date, $mappingName = Archiver::DEFAULT_MAPPING_NAME, $limit = 50)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $mappingName = $this->normalizeMappingName($mappingName);
        $limit = max(1, min((int) $limit, 200));

        $periodObj = PeriodFactory::build($period, $date);
        $start = $periodObj->getDateTimeStart()->toString('Y-m-d H:i:s');
        $end = $periodObj->getDateTimeEnd()->toString('Y-m-d H:i:s');

        $dao = new RulesDao();
        $rules = $dao->getRulesForSite($idSite, $mappingName);
        $engine = new RuleEngine();

        $lvaTable = Common::prefixTable('log_link_visit_action');
        $visitTable = Common::prefixTable('log_visit');
        $actionTable = Common::prefixTable('log_action');

        $sql = "SELECT la.name AS url, COUNT(*) AS nb_hits
                FROM {$lvaTable} lva
                INNER JOIN {$visitTable} lv ON lv.idvisit = lva.idvisit
                INNER JOIN {$actionTable} la ON la.idaction = lva.idaction_url
                WHERE lv.idsite = ?
                  AND la.type = 1
                  AND lva.server_time >= ?
                  AND lva.server_time <= ?
                GROUP BY lva.idaction_url
                ORDER BY nb_hits DESC
                LIMIT 5000";

        $rows = Db::fetchAll($sql, [(int) $idSite, $start, $end]);
        $unmapped = [];

        foreach ($rows as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $group = $engine->evaluateUrl($url, $rules);
            if ($group !== RuleEngine::getOthersGroupLabel()) {
                continue;
            }

            $unmapped[] = [
                'url' => $url,
                'nb_hits' => (int) $row['nb_hits'],
            ];

            if (count($unmapped) >= $limit) {
                break;
            }
        }

        return [
            'mapping' => $mappingName,
            'period' => $period,
            'date' => $date,
            'start' => $start,
            'end' => $end,
            'rows' => $unmapped,
        ];
    }

    private function validateRule($groupName, $pattern, $matchType)
    {
        if (empty(trim($groupName))) {
            throw new \Exception('Group name is required.');
        }

        if (mb_strlen($groupName) > 255) {
            throw new \Exception('Group name must be 255 characters or less.');
        }

        if (empty(trim($pattern))) {
            throw new \Exception('Pattern is required.');
        }

        if (mb_strlen($pattern) > 500) {
            throw new \Exception('Pattern must be 500 characters or less.');
        }

        if (!in_array($matchType, ['prefix', 'regex'], true)) {
            throw new \Exception('Match type must be "prefix" or "regex".');
        }

        if ($matchType === 'regex' && !RuleEngine::isValidRegex($pattern)) {
            throw new \Exception('Invalid regex pattern.');
        }
    }

    private function normalizeMappingName($mappingName): string
    {
        $mappingName = Archiver::normalizeMappingName($mappingName);

        if (mb_strlen($mappingName) > 100) {
            throw new \Exception('Mapping name must be 100 characters or less.');
        }

        return $mappingName;
    }

    private function validateNoConflicts($idSite, string $mappingName, string $groupName, string $pattern, string $matchType, $excludeRuleId = null): void
    {
        $dao = new RulesDao();
        $rules = $dao->getRulesForSite($idSite, $mappingName);

        if ($excludeRuleId !== null) {
            $rules = array_filter($rules, function ($rule) use ($excludeRuleId) {
                return (int) $rule['idrule'] !== (int) $excludeRuleId;
            });
        }

        if (empty($rules)) {
            return;
        }

        $candidate = [
            'group_name' => $groupName,
            'pattern' => $pattern,
            'match_type' => $matchType,
        ];

        foreach ($rules as $rule) {
            if ($rule['group_name'] === $groupName) {
                continue;
            }

            if ($this->hasDeterministicConflict($candidate, $rule)) {
                throw new \Exception(sprintf(
                    'Conflict detected in mapping "%s": this rule overlaps with existing rule #%d (%s: %s). A page can belong to only one group in a mapping.',
                    $mappingName,
                    (int) $rule['idrule'],
                    $rule['match_type'],
                    $rule['pattern']
                ));
            }
        }

        $engine = new RuleEngine();
        $recentUrls = $this->getRecentPageUrls($idSite);

        if (empty($recentUrls)) {
            return;
        }

        foreach ($recentUrls as $url) {
            if (!$engine->matchesRule($url, $candidate)) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule['group_name'] === $groupName) {
                    continue;
                }

                if ($engine->matchesRule($url, $rule)) {
                    throw new \Exception(sprintf(
                        'Conflict detected in mapping "%s": URL "%s" matches both this rule and existing rule #%d (%s).',
                        $mappingName,
                        mb_substr($url, 0, 300),
                        (int) $rule['idrule'],
                        $rule['group_name']
                    ));
                }
            }
        }
    }

    private function hasDeterministicConflict(array $candidate, array $existing): bool
    {
        $candidatePattern = (string) ($candidate['pattern'] ?? '');
        $candidateType = (string) ($candidate['match_type'] ?? 'prefix');
        $existingPattern = (string) ($existing['pattern'] ?? '');
        $existingType = (string) ($existing['match_type'] ?? 'prefix');

        if ($candidateType === $existingType && $candidatePattern === $existingPattern) {
            return true;
        }

        if ($candidateType === 'prefix' && $existingType === 'prefix') {
            return strpos($candidatePattern, $existingPattern) === 0
                || strpos($existingPattern, $candidatePattern) === 0;
        }

        return false;
    }

    private function getRecentPageUrls($idSite, int $lookbackDays = 90, int $limit = 4000): array
    {
        $since = Date::factory('now')->subDay($lookbackDays)->toString('Y-m-d H:i:s');

        $lvaTable = Common::prefixTable('log_link_visit_action');
        $visitTable = Common::prefixTable('log_visit');
        $actionTable = Common::prefixTable('log_action');

        $sql = "SELECT la.name AS url, MAX(lva.server_time) AS last_seen
                FROM {$lvaTable} lva
                INNER JOIN {$visitTable} lv ON lv.idvisit = lva.idvisit
                INNER JOIN {$actionTable} la ON la.idaction = lva.idaction_url
                WHERE lv.idsite = ?
                  AND la.type = 1
                  AND lva.server_time >= ?
                GROUP BY la.name
                ORDER BY last_seen DESC
                LIMIT {$limit}";

        $rows = Db::fetchAll($sql, [(int) $idSite, $since]);

        return array_values(array_filter(array_map(function ($row) {
            return $row['url'] ?? null;
        }, $rows)));
    }
}
