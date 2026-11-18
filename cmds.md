## Building the data locally

```
php artisan osm:import                   # → osm_highways  (downloads the Vermont .pbf)
php artisan osm:build-segments           # → road_segments + routing_edges

php artisan aadt:import                  # → aadt_sections (queries the VTrans service)
php artisan aadt:match                   # writes aadt onto each segment

php artisan crashes:import crashes       # → crashes (the ./crashes dir of CSVs)
php artisan crashes:match                # writes crash_count onto each segment

php artisan db:seed                      # dev only: test@example.com + 3 routes
```

The two `:match` steps must come after `osm:build-segments`, because it replaces segment geometry. If you ever re-run build-segments, re-run both matches after it.

`php artisan roads:rebuild --skip-load` does the last five in the right order for you.

Optional, for exercising the reporting path: `php artisan db:seed --class=KnownBadRoadSeeder`, then `osm:build-segments` again (reports only fold into danger_score on a rebuild) — and then the matches again.

## Shipping a new road network

`osm:import` shells out to osm2pgsql, which needs a native binary Laravel Cloud has no way to run. So the import happens in CI, which publishes the staging table as a release asset that any environment can load.

Run the **osm refresh** workflow from the Actions tab. It imports the extract, publishes `osm_highways.ndjson.gz` under a dated `osm-YYYY-MM-DD` release, and then rebuilds production from it. Untick *rebuild* to publish the artifact without touching production.

Geofabrik rebuilds its extracts once a day, so OSM edits made today land in a run from tomorrow onwards.

### Doing it by hand

```
php artisan osm:export                               # staging table → artifact
php artisan osm:load                                 # newest release → staging table
php artisan osm:load --release=osm-2026-10-02        # or a specific one
php artisan osm:load path/to/artifact.ndjson.gz      # or a local file
php artisan osm:load <artifact> --dry-run            # check it without writing

php artisan roads:rebuild                            # load + rebuild everything
php artisan roads:rebuild --with-imports             # also refresh AADT and crashes
```

To apply a published release to production yourself:

```
cloud command:run production --cmd='php artisan roads:rebuild --release=osm-2026-11-18' -n
```

A rebuild replaces the staging table in one transaction, so a load that fails part-way leaves the previous network in place. Rolling back is loading an older release tag.

### What has to be configured once

| Where | Name | Why |
| --- | --- | --- |
| GitHub repo secret | `LARAVEL_CLOUD_TOKEN` | lets CI run the rebuild on Cloud |
| Cloud environment | `OSM_ARTIFACT_TOKEN` | lets `osm:load` download from this private repo |

`OSM_ARTIFACT_TOKEN` is a fine-grained PAT needing only **Contents: Read** on this repository. CI uses its own `GITHUB_TOKEN` and needs nothing added.
