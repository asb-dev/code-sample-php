<?php

namespace common\helpers;

/**
 * Class MoneyHelper
 * @package common\helpers
 */
class MoneyHelper
{
    /**
     * @param $value
     * @param int $prec
     * @return string
     */
    public static function format($value, $prec = 10): string
    {
        if ($value === 0) {
            return '0';
        }

        if ((int) $value != $value) {
            return rtrim(number_format($value, $prec, '.', ''), "0");
        }
        
        return number_format($value, 0, '', '');        
    }
}
