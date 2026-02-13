<?php

namespace Piwik\Plugins\ContentGrouping\Reports;

use Piwik\Piwik;
use Piwik\Plugin\Report;
use Piwik\Plugin\ViewDataTable;
use Piwik\Plugins\Actions\Columns\Metrics\AverageTimeOnPage;
use Piwik\Plugins\Actions\Columns\Metrics\BounceRate;
use Piwik\Plugins\Actions\Columns\Metrics\ExitRate;

class GetContentGroups extends Report
{
    protected function init()
    {
        parent::init();

        $this->module = 'ContentGrouping';
        $this->action = 'getContentGroups';
        $this->categoryId = 'General_Actions';
        $this->subcategoryId = 'ContentGrouping_ContentGroups';
        $this->name = Piwik::translate('ContentGrouping_ContentGroups');
        $this->documentation = Piwik::translate('ContentGrouping_ReportDocumentation');
        $this->order = 40;

        $this->metrics = ['nb_hits', 'nb_visits'];
        $this->processedMetrics = [
            new AverageTimeOnPage(),
            new BounceRate(),
            new ExitRate(),
        ];

        $this->hasGoalMetrics = true;
        $this->actionToLoadSubTables = $this->action;
    }

    public function getMetrics()
    {
        $metrics = parent::getMetrics();
        $metrics['nb_visits'] = Piwik::translate('General_ColumnUniquePageviews');
        return $metrics;
    }

    public function configureView(ViewDataTable $view)
    {
        $view->config->columns_to_display = [
            'label', 'nb_hits', 'nb_visits', 'bounce_rate', 'avg_time_on_page', 'exit_rate',
        ];

        $view->config->show_goals = true;
        $view->config->addTranslation('label', Piwik::translate('ContentGrouping_ContentGroup'));
        $view->requestConfig->filter_sort_column = 'nb_hits';
        $view->requestConfig->filter_sort_order = 'desc';
    }
}
