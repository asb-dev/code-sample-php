<?php

namespace common\components\WebSockets\handlers;

use common\models\User;

/**
 * Class NotificationsHandler
 * @package common\components\WebSockets\handlers
 */
class NotificationsHandler
{
    /**
     * @param $data
     * @return array
     */
    public function run($data): array
    {
        if (!isset($data->uuid)) {
            return [];
        }
        
        $user = User::getByUUID($data->uuid);
        if (!$user) {
            return [];
        }
        
        \Yii::$app->notifications->sendToUser($user->id);
        
        return [];
    }
}