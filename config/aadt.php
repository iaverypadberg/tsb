<?php

use App\Models\AadtSection;

return [

    /*
    |--------------------------------------------------------------------------
    | Traffic count service
    |--------------------------------------------------------------------------
    |
    | The Vermont Agency of Transportation publishes Annual Average Daily
    | Traffic as an ArcGIS feature service. `aadt:import` queries it at the
    | service level, which answers for every layer in one request.
    |
    | The service holds the current year only and is rewritten each spring,
    | so there is nothing to download twice and no archive to fall back on.
    |
    */

    'service_url' => env(
        'AADT_SERVICE_URL',
        'https://maps.vtrans.vermont.gov/arcgis/rest/services/Layers/AADT/FeatureServer',
    ),

    /*
    |--------------------------------------------------------------------------
    | Layers
    |--------------------------------------------------------------------------
    |
    | Layer 0 is limited access roads, which is the interstates and their
    | ramps; layer 1 is everything else. Both are imported: a rider is only
    | ever on the second, but the first is what the roads beside it feed.
    |
    */

    'layers' => [
        AadtSection::LAYER_LIMITED,
        AadtSection::LAYER_OTHER,
    ],

    /*
    |--------------------------------------------------------------------------
    | Request limits
    |--------------------------------------------------------------------------
    |
    | The whole state is around 3,300 sections and 13 MB of Esri JSON, which
    | the service will happily answer in one request but PHP cannot decode
    | in a default 128 MB memory limit. Each layer is read in pages instead:
    | 500 sections peaks at around 115 MB of resident memory, where 1,500
    | runs out. Raise it only alongside the memory limit.
    |
    */

    'timeout' => (int) env('AADT_TIMEOUT', 120),

    'page_size' => (int) env('AADT_PAGE_SIZE', 500),

];
