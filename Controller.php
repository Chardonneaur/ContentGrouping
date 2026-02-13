<?php

namespace Piwik\Plugins\ContentGrouping;

use Piwik\Common;
use Piwik\Nonce;
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
            'nonce' => Nonce::getNonce('ContentGrouping.manage'),
        ]);
    }
}
