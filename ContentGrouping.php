<?php

namespace Piwik\Plugins\ContentGrouping;

use Piwik\Common;
use Piwik\Plugins\ContentGrouping\Dao\RulesDao;

class ContentGrouping extends \Piwik\Plugin
{
    public function registerEvents()
    {
        return [
            'Db.getTablesInstalled' => 'getTablesInstalled',
            'SitesManager.deleteSite.end' => 'onSiteDeleted',
            'Translate.getClientSideTranslationKeys' => 'getClientSideTranslationKeys',
        ];
    }

    public function install()
    {
        $dao = new RulesDao();
        $dao->install();
    }

    public function uninstall()
    {
        $dao = new RulesDao();
        $dao->uninstall();
    }

    public function getTablesInstalled(&$allTablesInstalled)
    {
        $allTablesInstalled[] = Common::prefixTable('content_grouping_rule');
    }

    public function onSiteDeleted($idSite)
    {
        $dao = new RulesDao();
        $dao->deleteRulesForSite($idSite);
    }

    public function getClientSideTranslationKeys(&$translationKeys)
    {
        $translationKeys[] = 'ContentGrouping_ContentGroups';
        $translationKeys[] = 'ContentGrouping_ManageRules';
        $translationKeys[] = 'ContentGrouping_AddRule';
        $translationKeys[] = 'ContentGrouping_EditRule';
        $translationKeys[] = 'ContentGrouping_DeleteRuleConfirm';
        $translationKeys[] = 'ContentGrouping_NoRulesYet';
        $translationKeys[] = 'ContentGrouping_InvalidateButton';
        $translationKeys[] = 'ContentGrouping_InvalidateProcessing';
        $translationKeys[] = 'ContentGrouping_InvalidateSuccess';
        $translationKeys[] = 'ContentGrouping_InvalidateError';
    }
}
