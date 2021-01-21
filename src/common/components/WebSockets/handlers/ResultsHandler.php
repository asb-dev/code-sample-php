<?php

namespace common\components\WebSockets\handlers;
use common\services\ResultService;
use common\models\games\DemoGame;

/**
 * Class ResultsHandler
 * @package common\components\WebSockets\handlers
 */
class ResultsHandler
{
    /**
     * @return array
     * @throws \Exception
     */
    public function run()
    {
        $resultService = new ResultService;
        return ['results' => $resultService->getResults(new DemoGame)];
    }
}
