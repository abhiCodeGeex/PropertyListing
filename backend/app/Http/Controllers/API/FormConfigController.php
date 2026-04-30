<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FormConfig;

class FormConfigController extends Controller
{
    public function getForm(string $form)
    {
        $config = FormConfig::where('form', $form)->first();
        // print_r($config);
        if (! $config) {
            return response()->json(['error' => 'Form config not found'], 404);
        }

        return response()->json($config);
    }
}
