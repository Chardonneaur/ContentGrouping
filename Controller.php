<?php

namespace Piwik\Plugins\ContentGrouping;

use Piwik\Common;
use Piwik\Piwik;
use Piwik\Plugins\SitesManager\API as SitesManagerAPI;
use Piwik\View;

class Controller extends \Piwik\Plugin\ControllerAdmin
{
    public function manage()
    {
        Piwik::checkUserHasAdminAccess($this->idSite);

        $sites = SitesManagerAPI::getInstance()->getSitesWithAdminAccess();

        return $this->renderTemplate('manage', [
            'idSite' => $this->idSite,
            'sites' => $sites,
        ]);
    }

    public function transitions(): string
    {
        Piwik::checkUserHasAdminAccess($this->idSite);

        $period  = Common::getRequestVar('period', 'range', 'string');
        $date    = Common::getRequestVar('date', 'last30', 'string');
        $segment = Common::getRequestVar('segment', '', 'string');

        return $this->renderTemplate('transitions', [
            'idSite'  => $this->idSite,
            'period'  => $period,
            'date'    => $date,
            'segment' => $segment,
        ]);
    }
}
