<?php

use Illuminate\Support\Facades\Route;

$spaIndexPath = function (): ?string {
    $candidates = [
        public_path('index.html'),
        public_path('spa/index.html'),
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
};

Route::get('/', function () use ($spaIndexPath) {
    $spaIndex = $spaIndexPath();

    if ($spaIndex) {
        return response()->file($spaIndex);
    }

    return view('welcome');
});

Route::fallback(function () use ($spaIndexPath) {
    $spaIndex = $spaIndexPath();

    if ($spaIndex) {
        return response()->file($spaIndex);
    }

    abort(404);
});
