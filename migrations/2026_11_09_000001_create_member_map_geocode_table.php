<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Answers from the geocoder, so one place is looked up once, not once per
 * member (Nominatim's usage policy asks for exactly this). Pruned on write:
 * see Geocoder::remember().
 */
return Migration::createTable('member_map_geocode', function (Blueprint $table) {
    $table->string('key', 64)->primary();
    $table->mediumText('result');
    $table->timestamp('created_at')->index();
});
