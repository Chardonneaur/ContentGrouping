<?php

namespace Piwik\Plugins\ContentGrouping\Reports;

use Piwik\Piwik;
use Piwik\Plugin\Report;
use Piwik\Plugin\ViewDataTable;
use Piwik\Plugins\ContentGrouping\Visualizations\GroupTransitions as GroupTransitionsViz;

class GetGroupTransitions extends Report
{
    protected function init()
    {
        parent::init();

        $this->module             = 'ContentGrouping';
        $this->action             = 'getGroupTransitionsReport';
        $this->categoryId         = 'General_Actions';
        $this->subcategoryId      = 'ContentGrouping_ContentGroups';
        $this->name               = Piwik::translate('ContentGrouping_GroupTransitions');
        $this->order              = 41;
        $this->metrics            = [];
        $this->processedMetrics   = [];
    }

    public function configureView(ViewDataTable $view)
    {
        $view->config->show_all_views_icons = false;
    }

    public function getDefaultTypeViewDataTable()
    {
        return GroupTransitionsViz::ID;
    }
}
