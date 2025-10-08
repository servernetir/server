<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DedicatedController extends Controller
{
    public function index()
    {
        return view('dedicated');
    }
}