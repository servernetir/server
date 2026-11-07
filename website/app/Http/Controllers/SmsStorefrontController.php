<?php

namespace App\Http\Controllers;

use App\Services\SmsResale\Controls;

class SmsStorefrontController extends Controller
{
    public function home(Controls $controls)
    {
        return view('pages.sms', ['plans' => $controls->publicPlans()]);
    }
}
