<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class ModuleController extends Controller
{
    public function show(string $module): RedirectResponse
    {
        if ($module === 'csms') {
            return redirect()->route('csms.portal');
        }
        if ($module === 'communication') {
            return redirect()->route('communication.index');
        }
        if ($module === 'documents') {
            return redirect()->route('document-control.index');
        }
        if ($module === 'policy') {
            return redirect()->route('policy.index');
        }

        return redirect()->route('dashboard', in_array($module, ['risk', 'audit'], true) ? ['module' => $module] : ['view' => $module]);
    }
}
