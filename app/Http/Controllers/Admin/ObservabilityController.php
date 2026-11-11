<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FarastObservabilityService;

class ObservabilityController extends Controller
{
    public function index(FarastObservabilityService $observability)
    {
        return view('admin.observability.index', [
            'health' => $observability->snapshot(),
        ]);
    }
}
