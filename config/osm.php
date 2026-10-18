<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default extract
    |--------------------------------------------------------------------------
    |
    | The OpenStreetMap extract `osm:import` downloads when no source is given.
    | Any Geofabrik .osm.pbf URL works, so switching region is a config change
    | rather than a code change.
    |
    */

    'extract_url' => env('OSM_EXTRACT_URL', 'https://download.geofabrik.de/north-america/us/vermont-latest.osm.pbf'),

    /*
    |--------------------------------------------------------------------------
    | Where extracts are kept
    |--------------------------------------------------------------------------
    |
    | Downloaded .pbf files land here. The directory is bind-mounted into the
    | osm2pgsql container as /pbf by docker-compose.yml.
    |
    */

    'extract_path' => storage_path('app/osm'),

    /*
    |--------------------------------------------------------------------------
    | osm2pgsql
    |--------------------------------------------------------------------------
    |
    | The compose service name is used as the database host when the import
    | runs in a container, since 127.0.0.1 there is the container itself.
    |
    */

    'compose_service' => 'osm2pgsql',

    'compose_db_host' => 'postgres',

    /*
    |--------------------------------------------------------------------------
    | Service roads to skip
    |--------------------------------------------------------------------------
    |
    | Over half of a typical extract is `highway=service`, and most of that is
    | private driveways and parking aisles nobody rides down. They are staged
    | anyway, but left out of `road_segments` so the routing graph stays
    | something like the road network a cyclist would recognise.
    |
    */

    'excluded_service_types' => [
        'driveway',
        'parking_aisle',
        'drive-through',
        'emergency_access',
        'slipway',
    ],

];
