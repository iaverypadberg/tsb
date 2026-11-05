<?php

use App\Models\Crash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * The export's header row, written exactly as the service publishes it.
 */
function crashCsvHeader(): string
{
    return '"ObjectID","Mapped","Crash Date","City/Town","Address","AOT Route","Crash Type",'
        .'"Collision Direction","Weather","Road Group","Report Number","Reporting Agency","Road Group ID",'
        .'"AOT Actual Milepoint","Animal","Time of Day","Intersection With","Impairment","Involving",'
        .'"Non Reportable Address","Reporting Agency ID","Road Characteristics","Road Condition",'
        .'"Street Address","Surface Condition","AOT Route ID","Coordinates"';
}

/**
 * One exported crash. Only the fields a test cares about are worth naming.
 */
function crashCsvRow(
    int $objectId = 4762,
    string $date = 'January 15, 2015 at 5:59 AM',
    string $crashType = 'Injury',
    string $coordinates = '44.552967895409665,-73.06919896814377',
    string $milepoint = '3.11',
    string $roadGroupId = '2',
): string {
    return implode(',', array_map(
        fn (string $value): string => '"'.$value.'"',
        [
            (string) $objectId, '', $date, 'Essex', 'FAU-5409(320 Old Stage Rd)', 'OLD STAGE RD.',
            $crashType, 'Single Vehicle Crash', 'Cloudy', 'Federal Aid Urban System', '15ES00264',
            'Essex PD.', $roadGroupId, $milepoint, 'None/Other', 'Night', '', 'None', 'Pedestrian',
            '', 'VT0040200', 'Not at a Junction', 'Road Surface Condition', 'FAU-5409(320 Old Stage Rd)',
            'Snow', 'U5409', $coordinates,
        ],
    ));
}

function writeCrashCsv(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'crashes').'.csv';
    file_put_contents($path, $body);

    return $path;
}

it('imports an exported crash into the database', function () {
    $path = writeCrashCsv(crashCsvHeader()."\n".crashCsvRow()."\n");

    $this->artisan('crashes:import', ['path' => $path])->assertSuccessful();

    $crash = Crash::sole();

    expect($crash->object_id)->toBe(4762)
        ->and($crash->crash_type)->toBe('Injury')
        ->and($crash->town)->toBe('Essex')
        ->and($crash->involving)->toBe('Pedestrian')
        ->and($crash->aot_milepoint)->toBe(3.11)
        ->and($crash->road_group_id)->toBe(2)
        ->and($crash->occurred_at->toDateTimeString())->toBe('2015-01-15 05:59:00')
        ->and($crash->geom)->not->toBeNull();

    unlink($path);
});

/**
 * Latitude comes first in the export and second in everything PostGIS does,
 * so getting this backwards would put every Vermont crash in Kazakhstan.
 */
it('reads the exported coordinate pair as latitude then longitude', function () {
    $path = writeCrashCsv(crashCsvHeader()."\n".crashCsvRow(coordinates: '44.5,-73.0')."\n");

    $this->artisan('crashes:import', ['path' => $path])->assertSuccessful();

    expect(round(Crash::sole()->geom->getLatitude(), 4))->toBe(44.5)
        ->and(round(Crash::sole()->geom->getLongitude(), 4))->toBe(-73.0);

    unlink($path);
});

it('imports the same file twice without duplicating anything', function () {
    $path = writeCrashCsv(crashCsvHeader()."\n".crashCsvRow()."\n".crashCsvRow(objectId: 5648)."\n");

    $this->artisan('crashes:import', ['path' => $path])->assertSuccessful();
    $this->artisan('crashes:import', ['path' => $path])->assertSuccessful();

    expect(Crash::count())->toBe(2);

    unlink($path);
});

it('refreshes a crash that was re-exported with a correction', function () {
    $path = writeCrashCsv(crashCsvHeader()."\n".crashCsvRow(crashType: 'Property Damage Only')."\n");
    $this->artisan('crashes:import', ['path' => $path])->assertSuccessful();

    $corrected = writeCrashCsv(crashCsvHeader()."\n".crashCsvRow(crashType: 'Fatal')."\n");
    $this->artisan('crashes:import', ['path' => $corrected])->assertSuccessful();

    expect(Crash::count())->toBe(1)
        ->and(Crash::sole()->crash_type)->toBe('Fatal');

    unlink($path);
    unlink($corrected);
});

/**
 * A crash with no usable position cannot be matched to a road, but dropping
 * it silently would make the import totals disagree with the file.
 */
it('keeps a crash that has no coordinates', function () {
    $path = writeCrashCsv(crashCsvHeader()."\n".crashCsvRow(coordinates: '')."\n");

    $this->artisan('crashes:import', ['path' => $path])->assertSuccessful();

    expect(Crash::count())->toBe(1)
        ->and(Crash::sole()->geom)->toBeNull();

    unlink($path);
});

/**
 * A transposed or truncated pair would otherwise be plotted in the ocean and
 * quietly matched to nothing.
 */
it('refuses coordinates that fall outside vermont', function () {
    $path = writeCrashCsv(crashCsvHeader()."\n".crashCsvRow(coordinates: '-73.069,44.552')."\n");

    $this->artisan('crashes:import', ['path' => $path])->assertSuccessful();

    expect(Crash::sole()->geom)->toBeNull();

    unlink($path);
});

it('keeps a crash whose date cannot be read', function () {
    $path = writeCrashCsv(crashCsvHeader()."\n".crashCsvRow(date: 'sometime last winter')."\n");

    $this->artisan('crashes:import', ['path' => $path])->assertSuccessful();

    expect(Crash::count())->toBe(1)
        ->and(Crash::sole()->occurred_at)->toBeNull();

    unlink($path);
});

it('fails on a file that is not a crash export', function () {
    $path = writeCrashCsv("\"Name\",\"Value\"\n\"a\",\"b\"\n");

    $this->artisan('crashes:import', ['path' => $path])->assertFailed();

    expect(Crash::count())->toBe(0);

    unlink($path);
});

it('fails on a file that is not there', function () {
    $this->artisan('crashes:import', ['path' => '/nowhere/crashes.csv'])->assertFailed();
});

/**
 * The published service caps how many records one request returns, so a file
 * landing exactly on the cap is almost certainly a truncated download. It is
 * imported, but the operator is told.
 */
it('warns when the file is exactly the size of the export cap', function () {
    $rows = '';

    for ($objectId = 1; $objectId <= 2000; $objectId++) {
        $rows .= crashCsvRow(objectId: $objectId)."\n";
    }

    $path = writeCrashCsv(crashCsvHeader()."\n".$rows);

    $this->artisan('crashes:import', ['path' => $path])
        ->expectsOutputToContain('export cap')
        ->assertSuccessful();

    expect(Crash::count())->toBe(2000);

    unlink($path);
});

it('imports every csv in a directory', function () {
    $directory = sys_get_temp_dir().'/crashes-'.uniqid();
    mkdir($directory);

    file_put_contents($directory.'/2024.csv', crashCsvHeader()."\n".crashCsvRow(objectId: 1)."\n");
    file_put_contents($directory.'/2025.csv', crashCsvHeader()."\n".crashCsvRow(objectId: 2)."\n");
    // Not a crash export, and not picked up.
    file_put_contents($directory.'/notes.txt', 'ignore me');

    $this->artisan('crashes:import', ['path' => $directory])->assertSuccessful();

    expect(Crash::count())->toBe(2);

    array_map('unlink', glob($directory.'/*'));
    rmdir($directory);
});

/**
 * Replacing one set of exports with another has to be able to drop crashes
 * the new files do not mention, which an upsert on its own cannot do.
 */
it('clears what it holds first when asked for a fresh import', function () {
    Crash::factory()->create(['object_id' => 999_999]);

    $path = writeCrashCsv(crashCsvHeader()."\n".crashCsvRow(objectId: 1)."\n");

    $this->artisan('crashes:import', ['path' => $path, '--fresh' => true])->assertSuccessful();

    expect(Crash::count())->toBe(1)
        ->and(Crash::sole()->object_id)->toBe(1);

    unlink($path);
});

it('keeps what it holds when a fresh import was not asked for', function () {
    Crash::factory()->create(['object_id' => 999_999]);

    $path = writeCrashCsv(crashCsvHeader()."\n".crashCsvRow(objectId: 1)."\n");

    $this->artisan('crashes:import', ['path' => $path])->assertSuccessful();

    expect(Crash::count())->toBe(2);

    unlink($path);
});

it('fails on a directory with no csv files in it', function () {
    $directory = sys_get_temp_dir().'/crashes-'.uniqid();
    mkdir($directory);

    $this->artisan('crashes:import', ['path' => $directory])->assertFailed();

    rmdir($directory);
});

/**
 * The exports run April to November, so the gap between two of them is the
 * closed season, not time anybody was watching. Counting it would halve
 * every crash rate.
 */
it('reports the window each year actually covers', function () {
    $path = writeCrashCsv(
        crashCsvHeader()."\n"
        .crashCsvRow(objectId: 1, date: 'April 1, 2024 at 9:00 AM')."\n"
        .crashCsvRow(objectId: 2, date: 'July 18, 2024 at 9:00 AM')."\n"
    );

    $this->artisan('crashes:import', ['path' => $path])
        ->expectsOutputToContain('2024-04-01 to 2024-07-18')
        ->assertSuccessful();

    unlink($path);
});

/**
 * The export cap can land across a whole season instead of clipping the end
 * of it, which leaves the first and last crash months apart and the year
 * looking complete while most of it is missing. Only the daily rate gives
 * that away, so the import compares the years against each other.
 */
it('flags a year holding far fewer crashes a day than the best covered one', function () {
    $rows = '';
    $objectId = 0;

    // A well covered season: many crashes spread across it.
    foreach (range(1, 60) as $day) {
        $date = Carbon::create(2024, 4, 1)->addDays($day % 30);
        $rows .= crashCsvRow(objectId: ++$objectId, date: $date->format('F j, Y').' at 9:00 AM')."\n";
    }

    // A thin one: the same span, a handful of crashes.
    foreach ([0, 15, 29] as $day) {
        $date = Carbon::create(2025, 4, 1)->addDays($day);
        $rows .= crashCsvRow(objectId: ++$objectId, date: $date->format('F j, Y').' at 9:00 AM')."\n";
    }

    $path = writeCrashCsv(crashCsvHeader()."\n".$rows);

    $this->artisan('crashes:import', ['path' => $path])
        ->expectsOutputToContain('(thin)')
        ->assertSuccessful();

    unlink($path);
});

it('does not flag years that are evenly covered', function () {
    $rows = '';
    $objectId = 0;

    foreach ([2024, 2025] as $year) {
        foreach (range(0, 29) as $day) {
            $date = Carbon::create($year, 4, 1)->addDays($day);
            $rows .= crashCsvRow(objectId: ++$objectId, date: $date->format('F j, Y').' at 9:00 AM')."\n";
        }
    }

    $path = writeCrashCsv(crashCsvHeader()."\n".$rows);

    $this->artisan('crashes:import', ['path' => $path])
        ->doesntExpectOutputToContain('(thin)')
        ->assertSuccessful();

    unlink($path);
});
