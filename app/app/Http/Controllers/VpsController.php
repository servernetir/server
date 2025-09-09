<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class VpsController extends Controller
{
    public function index(Request $request)
    {
        return view('order-vps');
    }
}