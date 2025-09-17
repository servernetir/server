<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class VpnController extends Controller
{
    public function index()
    {
        return view('vpn');
    }
}