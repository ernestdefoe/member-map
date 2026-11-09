<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Travel pins: "visiting Chicago Dec 3-7". Like a home location, the point is
 * the centre of the city, never what was searched. A trip stops showing the
 * day after it ends and is deleted on a later write (Trips::prune).
 */
return Migration::createTable('member_map_trips', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->decimal('lat', 7, 2);
    $table->decimal('lng', 7, 2);
    $table->string('label', 150);
    $table->string('country_code', 2)->nullable();
    $table->date('starts_on');
    $table->date('ends_on')->index();
    $table->timestamp('created_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
