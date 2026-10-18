# BadRoads — Build Plan (v1)

## Stack
- **Laravel 13** (PHP 8.3+), **Livewire** + Alpine for UI
- **PostgreSQL + PostGIS** (decision: see below)
- **pgRouting** for detour suggestions (MVP); GraphHopper/Valhalla as a later option
- Map: **MapLibre GL** or **Leaflet** driven from Alpine inside Livewire components
- Queues: Redis + Horizon for analysis jobs
- Packages: `clickbar/laravel-magellan` (PostGIS types/queries in Eloquent), `sibyx/phpgpx` (GPX parse/write)

## Database decision: PostgreSQL + PostGIS
The core of the app is geometry: "which road segments does this line touch?" and "find a path around this segment."
- PostGIS gives spatial indexes (GiST), `ST_DWithin`, `ST_Buffer`, `ST_LineLocatePoint`, `ST_LineSubstring` — exactly the operations needed.
- pgRouting runs shortest-path in the same DB, with your own danger score as edge cost.
- OSM data loads straight in via `osm2pgsql` / `osm2pgrouting`.
- MySQL has spatial types but no real routing and weaker geo functions; SQLite/SpatiaLite only for toy dev.

## User flow
1. User builds a route in Ride with GPS, exports GPX.
2. Uploads GPX (later: connect RWGPS account via OAuth and pick a route).
3. App analyzes route in a queued job; page shows progress.
4. Map shows route, flagged sections highlighted red, with reason ("no shoulder, 80 km/h, 3 crash reports").
5. (On hold for now)Each flagged section has a suggested detour (green) with +distance / +elevation.
6. (On hold for now)User accepts/rejects each suggestion, then downloads a new GPX to re-import into RWGPS.

## User flow 2
1. User knows about a dangerous road and wants to store that information somewhere in the app.
2. Provides them an interactive map where they can select a complete road or a specific sections of a road and flag it as dangerous. This should allow them to provide a text feedback which the admin can then look at as a justification.

## Data model
- `users`
- `routes` — user_id, name, source (gpx|rwgps), source_id, `geom LINESTRING(4326)`, distance_m, elevation_gain, original_gpx (storage path)
- `road_segments` — osm_way_id, name, `geom LINESTRING`, highway_type, speed_limit, lanes, has_shoulder, bike_infra (none|painted|buffered|protected|path), `danger_score` (0–100), source, updated_at
- `segment_reports` — road_segment_id, reason, severity, source/notes (where "known bad" info lives; populated by separate data task)
- `routing_edges` — pgRouting graph (source, target, cost, reverse_cost, geom) where cost = length × f(danger_score)
- `route_analyses` — route_id, status, started/finished, summary json
- `flagged_sections` — analysis_id, road_segment_id(s), start_fraction, end_fraction, `geom`, length_m, max_danger
- `suggestions` — flagged_section_id, `geom`, added_distance_m, added_elevation_m, danger_reduction, status (pending|accepted|rejected)

## Core algorithms
### 1. Matching the route to roads
- RWGPS routes are already snapped to roads, so a buffer approach works for MVP:
  - For each candidate segment within ~15 m (`ST_DWithin` on geography), require the route to run along it for > N metres **and** bearings to agree within ~30°.
  - The bearing check prevents false positives where a bike path runs right beside a highway.
- Upgrade path: proper map matching (Valhalla `trace_attributes`, or OSRM/GraphHopper match).

### 2. Flagging
- Segment is "bad" if danger_score ≥ threshold (user-adjustable: "cautious / normal / brave").
- Merge consecutive bad segments into one flagged section; ignore tiny bits (< 50 m) unless very high danger.

### 3. Detours
- For each flagged section, take an entry point a bit before and exit a bit after.
- Run pgRouting (`pgr_dijkstra` / `pgr_aStar`) on a bounding-box subgraph around those points, cost = length × danger penalty, bad segments heavily penalised.
- Reject detours that add more than X% / X km; if none, say "no reasonable alternative" instead of forcing one.
- Splice accepted detours into the route line with `ST_LineSubstring` and rebuild GPX.

## Livewire components
- `RouteUpload` — file upload + validation (GPX schema, size, point count)
- `RouteList` — user's routes and status
- `RouteAnalysis` — map + flagged sections list; polls job status (or Reverb later)
- `FlaggedSectionCard` — reason, detour stats, accept/reject
- `ExportRoute`(On hold for now) — download modified GPX
- `SegmentFlagger` - allow users to flag segments.
- Admin: `SegmentEditor` to manually mark/annotate roads

## Phases
0. **Setup** — Laravel 13, Postgres+PostGIS+pgRouting in Docker (Sail), auth (Breeze/Starter Kit), CI.
1. **Import & display** — GPX upload, parse, store geometry, show on map.
2. **Road data skeleton** — import OSM extract for one test region (osm2pgsql / osm2pgrouting), add danger_score column, hand-seed a few known bad roads.
3. **Detection** — matching + flagging job, highlight on map.
4. **Detours** — pgRouting suggestions, accept/reject, GPX export.
5. **Polish** — risk-tolerance settings, explanations, mobile layout, rate limits.
5.5 **Road/Segment Flagging** - Add the feature from User flow 2
6. **RWGPS integration**(On hold for now) — OAuth import, optionally push route back.
7. **Scale**(On hold for now) — more regions, scheduled OSM refresh, consider GraphHopper/Valhalla if routing gets slow.

## Risks / open questions
- Data quality of "bad roads" (separate task) determines usefulness; keep `source` + timestamps on everything.
- Parallel-road false positives → bearing check, user "this is wrong" feedback button.
- OSM data is ODbL — attribution required on the map/site.
- Region coverage: start with one area you ride; global OSM routing graph is large.
- RWGPS API access terms need checking before Phase 6.
- Cautious analyse setting flags everything(Even if its a dirt road).