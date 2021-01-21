<?php

namespace common\services;

use common\models\AtomicOperation;
use common\models\PartnerProfit;

/**
 * Class PartnerService
 * @package common\services
 */
class PartnerService
{

    const LIMIT = 1000;
    
    private $mockMode = false;
    
    
    public function __construct($mockMode = false)
    {
        $this->mockMode = $mockMode;
    }

    /**
     * @return array
     */
    public function proccess()
    {
        $offsetId = 0;
        
        $urls = [];

        do {
            $items = PartnerProfit::find()->
                where(['>', 'houseedge', 0])->
                andWhere(['>', 'id', $offsetId])->
                orderBy('id ASC')->
                limit(self::LIMIT)->
                all();

            foreach ($items as $item) {
                $offsetId = $item->id;
                
                $urls[] = $this->send($item);
            }
        } while (count($items) == self::LIMIT);
        
        if ($this->mockMode) {
            return $urls;
        }
    }

    /**
     * @param PartnerProfit $profit
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function send(PartnerProfit $profit)
    {
        if ($profit->payment <= 0) {
            return true;
        }

        $atomic = new AtomicOperation();
        $atomic->begin(\yii\db\Transaction::SERIALIZABLE);

        try {
            $payment = $profit->payment;

            $profit->updateCounters(['houseedge' => $profit->houseedge * -1]);

            $formattedPayment = \common\helpers\MoneyHelper::format($payment / $profit->currency->denominator);

            $atomic->commit();

            $callbacksUrls = $profit->partner->callbacksUrls;
            if (!$callbacksUrls) {
                return true;
            }

            $url = $callbacksUrls->getPreparedRsLink($profit->user,
                $formattedPayment, $profit->currency->code);
            
            if (!$url) {
                return true;
            }

            if ($this->mockMode) {
                return $url;
            }
            
            $this->sendCallback($url);

            return true;
        } catch (\Exception $e) {
            $atomic->rollback();
            throw $e;
        }
    }

    /**
     * @param $url
     * @throws \GuzzleHttp\Exception\GuzzleException|\Exception
     */
    private function sendCallback($url)
    {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->request('GET', $url);
            if ($response->getStatusCode() != 200) {
                throw new \Exception($response->getBody());
            }
        } catch (\Exception $e) {
            \Yii::error($e->getMessage() . PHP_EOL . ' In file ' . $e->getFile() . ' at line ' . $e->getLine());
            throw $e;
        }
    }
}
