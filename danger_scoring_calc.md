# How the danger score is calculated

Every road segment carries a `danger_score` from 0 to 100: how unpleasant or
dangerous that stretch is to ride a bike on. It is a transparent sum, not a
trained model — a rider should be able to read "55 mph, four lanes, no bike
lane" and see why a road was flagged.

Two columns hold it:

| Column | What it is |
| --- | --- |
| `base_danger_score` | What the OpenStreetMap tags alone say. Never includes any of the three adjustments. |
| `danger_score` | `base_danger_score` + reports + traffic + crashes, clamped to 0–100. |

Keeping the base separate is what makes the adjustments re-runnable: every
rescoring path starts again from `base_danger_score`, so a boost can never
compound on a second pass.

## The formula

```
danger_score = clamp(0, 100,
      base_danger_score          -- road class and tags   (0 … 100)
    + report_boost               -- local knowledge       (0 … +30)
    + traffic_adjustment         -- measured AADT        (-20 … +20)
    + crash_penalty              -- recorded crashes      (0 … +20)
)
```

The three adjustments are capped **individually** so no one signal can run
away with the total, and the sum is clamped at the end.

---

## Factor 1 — road class and tags

`app/Actions/RoadSegments/InterpretOsmHighway.php`

This is the only part that is computed at import time from the raw extract,
and it produces `base_danger_score`.

### Baseline by road class

| Class | Base | | Class | Base |
| --- | --- | --- | --- | --- |
| `motorway` | 100 | | `tertiary` | 30 |
| `motorway_link` | 95 | | `tertiary_link` | 28 |
| `trunk` | 77 | | `unclassified` | 20 |
| `trunk_link` | 72 | | `residential` | 15 |
| `primary` | 62 | | `service` / `track` | 10 |
| `primary_link` | 57 | | `bridleway` / `living_street` | 8 |
| `secondary` | 47 | | `path` | 5 |
| `secondary_link` | 42 | | `cycleway` | 0 |

Anything else scores 25. Motorways start at the ceiling because riding one is
not an option — routing has to treat them as impassable, not merely nasty.

### Modifiers on top of the baseline

| Signal | Effect | Why |
| --- | --- | --- |
| Speed limit ≤ 40 km/h (25 mph) | 0 | Thresholds sit on the km/h equivalents of the common US limits: 35, 45, 55 mph. US limits are tagged in mph, so the unit is parsed — reading "65 mph" as 65 km/h would badly understate the road. |
| ≤ 56 km/h (35 mph) | +8 | |
| ≤ 72 km/h (45 mph) | +16 | |
| ≤ 88 km/h (55 mph) | +24 | |
| above that | +32 | |
| Untagged speed | 0 | Unknown is not treated as slow. |
| 4+ lanes | +12 | |
| 3 lanes | +6 | |
| 1–2 lanes or untagged | 0 | |
| Route number (`ref`) present | +8 | A numbered road is a road *through* somewhere rather than *to* somewhere, so it carries through traffic. |
| Unpaved surface | −12 | About traffic, not comfort: gravel is harder work to ride but far less likely to have a lorry on it. Not given to tracks, paths, bridleways, cycleways, service or living streets, where it would buy nothing and would make a farm track look like an inviting detour. |
| Shoulder tagged present | −12 | |
| Shoulder tagged absent | +5 | |
| Shoulder untagged | 0 | Untagged means unknown, not shoulderless. |
| Bike provision | −35 separate path, −30 protected, −18 buffered, −10 painted, 0 none | Best provision found on either side of the road wins. |

### Why `ref` and `surface` carry so much of the weight

Road class is a loose proxy — in rural Vermont a `tertiary` road is usually a
quiet lane, not an arterial. The two signals that actually discriminate are
the ones that are both meaningful and well covered:

- **A route number means through traffic.** Primary and secondary ways carry
  one 95% and 84% of the time; tertiary and unclassified only 4% and 2%. The
  number, not the class, is what marks a through route.
- **An unpaved surface means a quiet road.** It is tagged on 41% of ways
  against 5% for speed limits, and no primary road in the state is unpaved
  while 56% of unclassified ways are.

---

## Factor 2 — rider reports (local knowledge)

`app/Models/SegmentReport.php`, summed in `RecalculateDangerScores`

```
report_boost = min(30, sum(severity) * 5)
```

Each report carries a severity of 1–5, each point is worth 5 points of score,
and the total is capped at 30 so local knowledge colours the score without
overriding what the road actually is. This is where the things tags cannot
hold live: a blind bend, a bridge with no shoulder, a junction riders know to
avoid.

Reports arrive either entered by hand or from a rider flag that has been
accepted in review, which is the one path that rescores a handful of roads
immediately (`ReviewRoadFlag` → `RecalculateDangerScores::handle()`) rather
than waiting for the next bulk run.

*Currently 74 reports across 74 road segments.*

---

## Factor 3 — measured traffic

`app/Actions/RoadSegments/ScoreTrafficVolume.php`

```
traffic_adjustment = clamp(-20, +20, round(5 * log2(aadt / 4000)))
```

What makes a road dangerous is the traffic on it, and OpenStreetMap does not
record traffic volume — class and route number are only proxies for it. Where
VTrans has actually counted a road, this corrects that guess with the real
figure.

| Constant | Value | Reasoning |
| --- | --- | --- |
| `PIVOT_AADT` | 4,000 | The median of the measured network (3,965 vehicles/day, rounded). A road counted at this is exactly what its tags suggested, so it gets no adjustment. |
| `POINTS_PER_DOUBLING` | 5 | |
| `MAX_ADJUSTMENT` | ±20 | Four doublings, saturating at 250 and 64,000 vehicles/day — either side of anything Vermont publishes. There to stop one surprising count rewriting a road wholesale, not to bind in normal use. |

**Why a logarithm.** AADT is heavily right skewed: 46 to 43,274 vehicles a day
across the measured network, median 3,965, mean 5,727. Scaled linearly the
tail eats the whole range, and the difference between a lane carrying 100 a
day and a road carrying 2,000 — the difference a rider cares about most —
would vanish. Read in doublings, 250 against 500 separates exactly as much as
20,000 against 40,000.

**Why it is signed.** It is a correction to a guess, not a penalty on top of
one, so a road quieter than the pivot *loses* points. The class baselines are
already high for the roads most likely to be counted (a trunk road averages 94
before anything is added), so a one-way penalty pinned 2,444 of the 8,976
measured roads to the 100 ceiling and left scores *less* spread out than they
started (SD 27.7 against 28.9). Signing it lifts that to 31.1.

**Where it is suppressed.** The adjustment is zero for anything with
`bike_infra = path`, and for `cycleway`, `path`, `bridleway`, `track`,
`service` and `living_street`. A path physically separated from a road is not
exposed to that road's traffic however much of it there is, so even a count
that genuinely belonged to it would be the wrong figure for its rider.

This used to be doing a second job as well, covering for a matcher that gave
those ways the neighbouring highway's count: `cycleway` segments were measured
at a median of 11,455 vehicles a day, reaching 42,346. That is now refused at
the matching instead, and no path, track or service road carries a count at
all.

**An unmeasured road scores 0 here, which is not the same as a quiet one.**
Coverage is partial by design and silence says nothing either way.

### How a road gets a count (`aadt:match`)

The two datasets describe the same roads and agree on nothing else, so
geometry does the joining — names cannot: US 2 is one name over 97 published
sections running from 44 to 47,635 vehicles a day. Each way is sampled every
25 m, each sample takes the nearest count section within 20 m whose bearing
agrees within 30°, and a road keeps a figure only if at least 60% of its
samples matched.

**Why that is not enough on its own.** A section is drawn down a route's
centreline, so the road carrying the traffic is already ten metres off it and
the buffer has to be loose enough to reach. Anything else inside that buffer
on the same bearing is then just as eligible, and beside a busy road there
usually is something: a separated bike path, a rail trail, a frontage road, a
parking aisle, the street behind. Three further tests say whose count it is.

| Test | What it refuses |
| --- | --- |
| `NOT_ON_COUNTED_NETWORK` | Ways VTrans publishes no count for, so a figure landing on one came off the road beside it: `cycleway`, `footway`, `path`, `bridleway`, `steps`, `pedestrian`, `track`, `service`, `construction`, `proposed`. Residential and unclassified streets stay eligible — a counted town highway is usually tagged as one. |
| `THROUGH_ROUTE_CLASSES` | A numbered section's count on a road that plausibly is not that route. The way has to carry the number, or carry none and be drawn as a through road: `ref` is missing from numbered roads often enough (58 primary and 24 secondary matched a numbered section without it) that absence cannot disqualify, but a `residential` street is not US 7 however close it runs. |
| `RIVAL_MARGIN_M` (6 m) | A count another road is clearly nearer to, measured at the point on the section nearest the sample. A rival has to run alongside the section and be a different road: never one carrying the same route number or the same name, which is how a divided highway's second carriageway is drawn. |

Against the 2025 counts those refuse 1,411 and 1,817 samples respectively,
which costs 834 of 8,976 roads their figure and takes `cycleway`, `path`,
`track` and `service` from 560 measured segments to none. Coverage of the
roads actually counted barely moves — primary 93%, secondary 89%, motorway
98%, trunk 93% of their length — and 2,940 of the 2,944 non-ramp sections
still reach a road. `aadt:match` prints both refusal counts next to the route
number agreement so a run that starts throwing away real roads says so.

---

## Factor 4 — recorded crash history

`app/Actions/RoadSegments/ScoreCrashHistory.php`

```
rate        = crash_count / max(length_m, 100)/1000 / years_observed
ceiling     = min(20, 8 * crash_count)
crash_penalty = crash_count == 0
              ? 0
              : round(clamp(2, ceiling, 4 * log2(rate / 0.5)))
```

Of everything the score is built from this is the only signal that is neither
a tag nor a proxy: somebody crashed here and a police officer wrote it down.

| Constant | Value | Reasoning |
| --- | --- | --- |
| `PIVOT_CRASHES_PER_KM_YEAR` | 0.5 | Set near the lower quartile of roads with any crash at all, so the curve is already climbing across the bulk of them rather than flattening against the floor. |
| `POINTS_PER_DOUBLING` | 4 | |
| `MIN_PENALTY` | 2 | What any road with a recorded crash takes, however sparse the evidence. |
| `MAX_PENALTY` | 20 | Held level with the traffic adjustment deliberately: crashes are the most direct evidence here, but the record is partial enough that it should not outweigh everything else about a road. |
| `MAX_PENALTY_PER_CRASH` | 8 | Two doublings' worth. One crash on a 50 m way works out at 10/km however the denominator is floored, so this ties how hard the rate can bite to how much actually happened to support it. |
| `MIN_EXPOSURE_M` | 100 | Reported coordinates are placed to within a few tens of metres — the same order as the shortest segments — so a crash on a 14 m way would otherwise read as 71 crashes per kilometre. |

**Why a rate, not a count.** Counting crashes per road would mostly measure
how OpenStreetMap happens to have cut the road up: segments run from 14 m to
21 km. A raw count correlates with segment length at 0.205; crashes per
kilometre correlate at −0.013, so dividing by length removes the artefact
almost exactly.

**Why it is divided by time.** So that loading a longer export makes the
scores better *evidenced* rather than uniformly worse. The span is summed per
year rather than taken end to end, because the exports are seasonal — they run
April to November, when people ride here. Measuring from the first crash to
the last would count every winter in between as observed time and halve every
rate. The current 7 seasons sum to 1,597 days, i.e. **4.37 years observed**,
where the calendar distance would claim 6.5.

**Why a logarithm.** Same reason as traffic, harder: over roads with any crash
the rate runs 0.02 to 148.7 per km per year, median 0.97, p95 9.2. Read
linearly, every ordinary road would sit indistinguishably near nought.

**Why it only ever adds.** Nearly 90% of the network has no recorded crash,
and that is not evidence of safety — the published export is capped per
request (2024 and 2025 both come back at exactly 4,000 rows), the history is
partial, and most quiet lanes are quiet in the record because nobody has had a
reportable crash on them *yet*. Crediting a road for an empty crash history
would reward roads for being absent from a dataset.

### How a crash gets a road (`crashes:match`)

A crash is published as a single point, so unlike a traffic count it belongs
to at most one road: a nearest-neighbour search within a 30 m buffer, with
`match_distance_m` stored for every match so a surprising one can be examined
rather than merely trusted. The buffer is wider than the traffic matcher's
20 m because a crash is a point placed by a reporting officer, and a crash on
a verge or in a junction is still a crash on that road. A junction crash
genuinely involves both roads and is given to the nearer one; splitting it
would count it twice.

---

## Worked examples

**A numbered secondary road, 55 mph, with crashes on it**

```
secondary                     47
55 mph (88 km/h)             +24
2 lanes                        0
route number (VT-116)         +8
                        ------- base_danger_score = 79
AADT 6,000   5*log2(6000/4000) = +2.9 → +3
6 crashes / 1.2 km / 4.37 yr = 1.14 per km·yr
             4*log2(1.14/0.5) =  +4.8 → +5
                        ------- danger_score = 87
```

**A gravel town road nobody has counted**

```
unclassified                  20
unpaved                      −12
no count, no crashes           0
                        ------- danger_score = 8
```

**One crash on a short unmeasured residential street**

```
residential                   15
1 crash / 100 m floor / 4.37 yr = 2.29 per km·yr
  4*log2(2.29/0.5) = 8.8, capped by 8*1 crash  → +8
                        ------- danger_score = 23
```

The same single crash on a 2 km road rates 0.11 per km·yr, which the curve
puts below zero, so the floor gives it +2 instead.

---

## When the score is recalculated

| Trigger | Scope |
| --- | --- |
| `osm:build-segments` | Whole network, in one SQL statement. Recomputes the base from the extract and reapplies all three adjustments; it reads `aadt` and `crash_count` back off the stored road, since an upsert leaves those columns alone. |
| `aadt:match` | Whole network (`handleAll()`) — the counts have moved under every road. |
| `crashes:match` | Whole network (`handleAll()`), for the same reason. |
| A flag accepted or withdrawn in review | Just the affected roads (`handle([$ids])`), so local knowledge shows up without waiting for the next OpenStreetMap run. |

Because of the bulk paths, every factor's arithmetic exists twice: once in PHP
for a single road and once as SQL for the whole network. Both live in the same
class — `ScoreTrafficVolume::sqlExpression()` and
`ScoreCrashHistory::sqlExpression()` — beside the constants, so the numbers
are written down only once, and a test asserts Postgres and PHP agree.

Note that `osm:build-segments` replaces geometry, so `crashes:match` should be
re-run after an extract import.

---

## What the score can and cannot see

*Across 86,689 road segments:*

| Signal | Coverage | Note |
| --- | --- | --- |
| Road class | 100% | Every road has one, but it is a proxy for traffic, not a measure of it. |
| Surface | 55.3% | |
| Route number | 7.9% | |
| Speed limit | 8.2% | |
| Shoulder | 28 segments | Far too few to rely on, which is why untagged is treated as unknown. |
| Measured AADT | 10.4% | Mostly the numbered highways. |
| Any recorded crash | 10.2% | 4,542 roads have both a count and a crash history. |
| Rider reports | 74 segments | |

Current distribution: mean 16.4, median 10, SD 21.2. 1,862 segments sit at the
100 ceiling and 2,817 at the floor; 11,359 have been moved off their base
score by one of the three adjustments.

The biggest weakness is still traffic volume. Where the state has measured a
road the real figure adjusts the score; everywhere else — nine roads in ten —
the class is only a guess at how busy it is, so a quiet rural highway scores
like a busy suburban one of the same class.

### Data that is collected but not yet scored

- **Crash severity.** `crash_injury_count` and `crash_fatal_count` are
  totalled onto every road by `crashes:match`, but `ScoreCrashHistory` reads
  only `crash_count`: 6,998 injury and 292 fatal crashes currently score
  exactly the same as 20,192 property-damage ones. (Severity is also missing
  on about a third of the 2020 and 2021 rows, so weighting it would
  under-penalise the older seasons.)
- **Who was involved.** The `crashes.involving` column holds 380 bicycle and
  409 pedestrian crashes — the most directly relevant evidence this
  application has — and nothing reads it.
- **Crash conditions.** `impairment`, `road_characteristics`,
  `surface_condition`, `weather` and `time_of_day` are imported verbatim and
  unused.
- **The calibration notes in `ScoreCrashHistory` predate the current import.**
  They quote a median rate of 1.38 per km·yr and a p95 of 31, from when the
  table held three seasons; across the seven now loaded it is 0.97 and 9.2.
  The pivot of 0.5 was chosen as the lower quartile of roads with a crash,
  which is now 0.31.
