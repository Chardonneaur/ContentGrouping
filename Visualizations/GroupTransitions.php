<?php

namespace Piwik\Plugins\ContentGrouping\Visualizations;

use Piwik\Plugin\Visualization;

class GroupTransitions extends Visualization
{
    const ID            = 'ContentGrouping.GroupTransitions';
    const TEMPLATE_FILE = '@ContentGrouping/dataTableViz_transitions.twig';

    public function beforeRender()
    {
        $this->config->show_visualization_only = true;
        $this->config->show_all_views_icons    = false;
        $this->config->show_footer             = false;
        $this->config->show_search             = false;
        $this->config->show_related_reports    = false;
    }
}
