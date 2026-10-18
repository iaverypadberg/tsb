<?php

namespace App\Actions\Routes;

use App\Models\Route;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Persists an uploaded GPX file as a route.
 *
 * The original file is kept so the export in a later phase can splice detours
 * into it and preserve per-point elevation, which the 2D route line drops.
 */
class StoreGpxRoute
{
    public function __construct(private ParseGpxFile $parser) {}

    /**
     * @throws InvalidGpxFile
     */
    public function handle(User $user, UploadedFile $file): Route
    {
        $parsed = $this->parser->handle(
            contents: (string) $file->get(),
            fallbackName: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: __('Untitled route'),
        );

        $path = $file->store("routes/{$user->id}");

        return DB::transaction(function () use ($user, $parsed, $path): Route {
            $route = Route::create([
                'user_id' => $user->id,
                'name' => $parsed->name,
                'source' => Route::SOURCE_GPX,
                'geom' => $parsed->geometry,
                'elevation_gain_m' => $parsed->elevationGainM,
                'point_count' => $parsed->pointCount,
                'original_gpx_path' => $path,
            ]);

            // Measured on the geography type so the result is real metres on
            // the spheroid rather than degrees.
            DB::update(
                'update routes set distance_m = round(ST_Length(geom::geography)) where id = ?',
                [$route->id],
            );

            return $route->refresh();
        });
    }
}
