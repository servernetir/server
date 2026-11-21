<?php

namespace App\Auth;

use Illuminate\Auth\SessionGuard;

class HostSessionGuard extends SessionGuard
{
    public function getRecallerName(): string
    {
        $name = 'remember_'.$this->name.'_'.sha1(SessionGuard::class);

        return config('session.host_isolated') ? '__Host-'.$name : $name;
    }
}
