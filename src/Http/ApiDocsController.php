<?php

namespace Souqbox\ApiDocs\Http;

use Souqbox\ApiDocs\ApiDocsBuilder;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

/**
 * Serves the pre-built docs page when the web server doesn't serve public/docs itself.
 */
class ApiDocsController extends Controller
{
    /**
     * Return the built docs page, or instructions to build it when it doesn't exist yet.
     */
    public function __invoke(ApiDocsBuilder $builder): Response
    {
        if (! $builder->isBuilt()) {
            return response()->view('api-docs::missing', [], 503);
        }

        return response(File::get($builder->outputPath('index.html')), 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
