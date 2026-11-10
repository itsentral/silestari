<?php

namespace App\Http\Controllers;

use App\Support\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('dashboard', ['modules' => array_filter(ModuleRegistry::all(), fn (string $key): bool => $request->user()->hasModule($key), ARRAY_FILTER_USE_KEY)]);
    }
}
