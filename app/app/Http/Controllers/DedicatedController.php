<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DedicatedController extends Controller
{
    public function index(Request $request)
    {
        return view('order-dedicated');
    }
}