<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LimitsController extends Controller
{
    public function index()
    {
        return view('limits');
    }
}