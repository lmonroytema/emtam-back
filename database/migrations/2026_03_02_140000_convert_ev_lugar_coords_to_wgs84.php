<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ev_lugar_coordenada_mst')) {
            return;
        }

        $zone = 30;
        $northHemisphere = true;

        $convert = static function (float $easting, float $northing) use ($zone, $northHemisphere): array {
            $a = 6378137.0;
            $eccSquared = 0.00669438;
            $k0 = 0.9996;

            $x = $easting - 500000.0;
            $y = $northing;
            if (! $northHemisphere) {
                $y -= 10000000.0;
            }

            $longOrigin = ($zone - 1) * 6 - 180 + 3;
            $eccPrimeSquared = $eccSquared / (1 - $eccSquared);

            $M = $y / $k0;
            $mu = $M / ($a * (1 - $eccSquared / 4 - 3 * $eccSquared * $eccSquared / 64 - 5 * $eccSquared * $eccSquared * $eccSquared / 256));

            $e1 = (1 - sqrt(1 - $eccSquared)) / (1 + sqrt(1 - $eccSquared));
            $phi1Rad = $mu
                + (3 * $e1 / 2 - 27 * $e1 * $e1 * $e1 / 32) * sin(2 * $mu)
                + (21 * $e1 * $e1 / 16 - 55 * $e1 * $e1 * $e1 * $e1 / 32) * sin(4 * $mu)
                + (151 * $e1 * $e1 * $e1 / 96) * sin(6 * $mu)
                + (1097 * $e1 * $e1 * $e1 * $e1 / 512) * sin(8 * $mu);

            $N1 = $a / sqrt(1 - $eccSquared * sin($phi1Rad) * sin($phi1Rad));
            $T1 = tan($phi1Rad) * tan($phi1Rad);
            $C1 = $eccPrimeSquared * cos($phi1Rad) * cos($phi1Rad);
            $R1 = $a * (1 - $eccSquared) / pow(1 - $eccSquared * sin($phi1Rad) * sin($phi1Rad), 1.5);
            $D = $x / ($N1 * $k0);

            $latRad = $phi1Rad - ($N1 * tan($phi1Rad) / $R1)
                * ($D * $D / 2
                    - (5 + 3 * $T1 + 10 * $C1 - 4 * $C1 * $C1 - 9 * $eccPrimeSquared) * pow($D, 4) / 24
                    + (61 + 90 * $T1 + 298 * $C1 + 45 * $T1 * $T1 - 252 * $eccPrimeSquared - 3 * $C1 * $C1) * pow($D, 6) / 720);

            $lonRad = ($D
                - (1 + 2 * $T1 + $C1) * pow($D, 3) / 6
                + (5 - 2 * $C1 + 28 * $T1 - 3 * $C1 * $C1 + 8 * $eccPrimeSquared + 24 * $T1 * $T1) * pow($D, 5) / 120)
                / cos($phi1Rad);

            $lat = rad2deg($latRad);
            $lon = $longOrigin + rad2deg($lonRad);

            return [$lat, $lon];
        };

        DB::table('ev_lugar_coordenada_mst')->orderBy('ev_lu_coo-id')->chunk(200, function ($rows) use ($convert) {
            foreach ($rows as $row) {
                $esteRaw = $row->{'ev_lu_coo-este'} ?? null;
                $norteRaw = $row->{'ev_lu_coo-norte'} ?? null;
                if (! is_numeric($esteRaw) || ! is_numeric($norteRaw)) {
                    continue;
                }
                $este = (float) $esteRaw;
                $norte = (float) $norteRaw;
                if (abs($este) <= 180 && abs($norte) <= 90) {
                    continue;
                }

                [$lat, $lon] = $convert($este, $norte);

                DB::table('ev_lugar_coordenada_mst')
                    ->where('ev_lu_coo-id', $row->{'ev_lu_coo-id'})
                    ->update([
                        'ev_lu_coo-este' => (string) $lon,
                        'ev_lu_coo-norte' => (string) $lat,
                        'ev_lu_coo-srid' => '4326',
                    ]);
            }
        });
    }

    public function down(): void
    {
    }
};
