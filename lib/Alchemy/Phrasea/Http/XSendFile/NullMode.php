<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Http\XSendFile;

use Symfony\Component\HttpFoundation\Request;

class NullMode implements ModeInterface
{
    /**
     * {@inheritdoc}
     */
    public function setHeaders(Request $request)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getVirtualHostConfiguration()
    {
        return "\n";
    }
}
