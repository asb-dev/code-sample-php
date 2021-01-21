<?php

namespace common\components\Affiliates\handlers;

use common\components\Affiliates\Event;
use common\services\FinancesService;

/**
 * Class GameHouseEdgeReward
 * @package common\components\Affiliates\handlers
 */
class GameHouseEdgeReward extends BaseHandler
{

    /**
     * @param Event $event
     * @return bool
     * @throws \Exception
     */
    public function run(Event $event): bool
    {
        if (!parent::run($event)) {
            return false;
        }

        if (!isset($event->extData['user']) ||
            !isset($event->extData['houseedge']) ||
            !isset($event->extData['currency'])) {

            return false;
        }

        $aff = $event->extData['user']->affiliate;
        if (!$aff) {
            return false;
        }

        $currency = $event->extData['currency'];
        $houseedge = (int)$event->extData['houseedge'];
        $finService = new FinancesService;
        $finService->updateAffProfit($aff, $currency, $houseedge);

        $finService = new FinancesService;
        $finService->updatePartnerProfit($aff, $event->extData['user'], $currency, $houseedge);

        $affiliateStat = $aff->affiliateStats;
        if ($affiliateStat) {
            $affiliateStat->dice_bets++;
            $affiliateStat->save();
        }

        return true;
    }
}
