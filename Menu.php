<?php

namespace Piwik\Plugins\ContentGrouping;

use Piwik\Common;
use Piwik\Menu\MenuAdmin;
use Piwik\Piwik;
use Piwik\Plugins\UsersManager\UserPreferences;

class Menu extends \Piwik\Plugin\Menu
{
    public function configureAdminMenu(MenuAdmin $menu)
    {
        $userPreferences = new UserPreferences();
        $default = $userPreferences->getDefaultWebsiteId();
        $idSite = Common::getRequestVar('idSite', $default, 'int');

        if (Piwik::isUserHasAdminAccess($idSite)) {
            $menu->addMeasurableItem(
                'ContentGrouping_ContentGroups',
                $this->urlForAction('manage'),
                $orderId = 42
            );
        }
    }
}
