<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

class ApiDocsController extends Controller
{
    public function index(): View
    {
        abort_unless((bool) config('pastara.api_docs_enabled', false), 404);

        return view('api-docs', [
            'specUrl' => route('api.docs.spec', [], false),
            'appName' => (string) config('app.name', 'Pastara API'),
        ]);
    }

    public function spec(): Response
    {
        abort_unless((bool) config('pastara.api_docs_enabled', false), 404);

        $path = resource_path('openapi/openapi.yaml');
        abort_unless(is_file($path), 404);

        $contents = file_get_contents($path);
        abort_if($contents === false, 404);

        return response($contents, 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
