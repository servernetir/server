<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SoftController extends Controller
{
    public function index(Request $request)
    {
        return view('order-soft');
    }
}