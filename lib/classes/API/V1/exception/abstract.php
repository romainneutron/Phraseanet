<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

abstract class API_V1_exception_abstract extends Exception
{
    protected static $details;

    public static function get_details()
    {
        return static::$details;
    }
}
