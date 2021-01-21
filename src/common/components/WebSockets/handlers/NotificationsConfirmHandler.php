<?php

namespace common\components\WebSockets\handlers;

use common\models\User;
use common\models\Notification;

/**
 * Class NotificationsConfirmHandler
 * @package common\components\WebSockets\handlers
 */
class NotificationsConfirmHandler
{
    /**
     * @param $data
     * @return array
     */
    public function run($data)
    {
        if (!isset($data->uuid) || !isset($data->id)) {
            return [];
        }
        
        $user = User::getByUUID($data->uuid);
        if (!$user) {
            return [];
        }
        
        $notification = Notification::find()->
            where(['user_id' => $user->id, 'id' => $data->id])->
            one();
        
        if (!$notification) {
            return [];
        }
        
        $notification->status = Notification::STATUS_READ;
        $notification->save();
        
        return [];
    }
}