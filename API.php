<?php

namespace Piwik\Plugins\ContentGrouping;

use Piwik\Archive;
use Piwik\Archive\ArchiveInvalidator;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\Date;
use Piwik\Piwik;
use Piwik\Site;
use Piwik\Plugins\ContentGrouping\Dao\RulesDao;
use Piwik\Plugins\ContentGrouping\Model\RuleEngine;

/**
 * @method static API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    // ---- Report ----

    public function getContentGroups($idSite, $period, $date, $segment = false, $idSubtable = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $dataTable = Archive::createDataTableFromArchive(
            Archiver::CONTENT_GROUPS_RECORD_NAME,
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
            'output' => $result->makeOutputLogs(),
        ];
    }

    // ---- Rules CRUD ----

    public function getRules($idSite)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $dao = new RulesDao();
        return $dao->getRulesForSite($idSite);
    }

    public function addRule($idSite, $groupName, $pattern, $matchType = 'prefix', $priority = 0)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $this->validateRule($groupName, $pattern, $matchType);

        $dao = new RulesDao();
        return $dao->addRule($idSite, $groupName, $pattern, $matchType, (int) $priority);
    }

    public function updateRule($idSite, $idRule, $groupName, $pattern, $matchType = 'prefix', $priority = 0)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $this->validateRule($groupName, $pattern, $matchType);

        $dao = new RulesDao();
        $existing = $dao->getRule($idRule, $idSite);
        if (empty($existing)) {
            throw new \Exception('Rule not found.');
        }

        $dao->updateRule($idRule, $idSite, [
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

    public function testUrl($idSite, $url)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $dao = new RulesDao();
        $rules = $dao->getRulesForSite($idSite);

        $engine = new RuleEngine();
        return ['group' => $engine->evaluateUrl($url, $rules)];
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
}
