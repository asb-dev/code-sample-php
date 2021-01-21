<?php

namespace common\components\Affiliates\handlers;

use common\components\Affiliates\Event;

/**
 * Class BaseHandler
 * @package common\components\Affiliates\handlers
 */
abstract class BaseHandler
{

    public $enabled = true;

    /**
     * @param Event $event
     * @return bool
     */
    public function run(Event $event): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return true;
    }
}
