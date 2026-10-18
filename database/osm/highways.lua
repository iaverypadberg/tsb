-- osm2pgsql flex configuration for BadRoads.
--
-- Loads the highway ways a cyclist could ride, or needs steered away from,
-- into the `osm_highways` staging table with their tags untouched. All
-- interpretation (danger scoring, shoulder and bike-infrastructure guesses)
-- happens later in `php artisan osm:build-segments`, so the scoring can be
-- retuned without re-importing the extract.
--
-- The table shape here must stay in step with the create_osm_highways_table
-- migration, which exists so tests and fresh installs have the table too.

local highways = osm2pgsql.define_table({
    name = 'osm_highways',
    ids = { type = 'way', id_column = 'osm_way_id' },
    columns = {
        { column = 'name',          type = 'text' },
        { column = 'ref',           type = 'text' },
        { column = 'highway',       type = 'text', not_null = true },
        { column = 'maxspeed',      type = 'text' },
        { column = 'lanes',         type = 'text' },
        { column = 'shoulder',      type = 'text' },
        { column = 'cycleway',      type = 'text' },
        { column = 'cycleway_left', type = 'text' },
        { column = 'cycleway_right',type = 'text' },
        { column = 'cycleway_both', type = 'text' },
        { column = 'bicycle',       type = 'text' },
        { column = 'surface',       type = 'text' },
        { column = 'oneway',        type = 'text' },
        { column = 'access',        type = 'text' },
        { column = 'service_type',  type = 'text' },
        { column = 'geom',          type = 'linestring', projection = 4326, not_null = true },
    },
})

-- Roads worth knowing about. Motorways are included deliberately: a cyclist
-- cannot ride them, so routing must be able to see and avoid them.
local wanted = {
    motorway = true, motorway_link = true,
    trunk = true, trunk_link = true,
    primary = true, primary_link = true,
    secondary = true, secondary_link = true,
    tertiary = true, tertiary_link = true,
    unclassified = true,
    residential = true,
    living_street = true,
    service = true,
    track = true,
    cycleway = true,
    path = true,
    bridleway = true,
}

function osm2pgsql.process_way(object)
    local highway = object.tags.highway

    if not highway or not wanted[highway] then
        return
    end

    -- Areas tagged as highways are car parks and squares, not routable lines.
    if object.is_closed and object.tags.area == 'yes' then
        return
    end

    highways:insert({
        name           = object.tags.name,
        ref            = object.tags.ref,
        highway        = highway,
        maxspeed       = object.tags.maxspeed,
        lanes          = object.tags.lanes,
        shoulder       = object.tags.shoulder,
        cycleway       = object.tags.cycleway,
        cycleway_left  = object.tags['cycleway:left'],
        cycleway_right = object.tags['cycleway:right'],
        cycleway_both  = object.tags['cycleway:both'],
        bicycle        = object.tags.bicycle,
        surface        = object.tags.surface,
        oneway         = object.tags.oneway,
        access         = object.tags.access,
        service_type   = object.tags.service,
        geom           = object:as_linestring(),
    })
end
