<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One row per member on the map. lat/lng are ALWAYS the centre of a named
 * place (a city, a region or a country) rounded for that level, never the
 * point the member searched or tapped: see Geocoder::place().
 */
return Migration::createTable('member_map_locations', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->decimal('lat', 7, 2);
    $table->decimal('lng', 7, 2);
    $table->string('precision', 8);
    $table->string('label', 150);
    $table->boolean('show_on_profile')->default(true);
    $table->timestamp('updated_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
