<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    private const SIDEBAR_COOKIE_PREFIX = 'sidebar_state_';

    /**
     * Determine whether encryption has been disabled for the given cookie.
     *
     * @param  string  $name
     */
    public function isDisabled($name): bool
    {
        return str_starts_with($name, self::SIDEBAR_COOKIE_PREFIX)
            || parent::isDisabled($name);
    }
}
