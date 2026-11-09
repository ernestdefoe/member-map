<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Meetups: a place and a time, hosted by a discussion. Who may see a meetup is
 * who may see its discussion (Discussion::whereVisibleTo at read time).
 */
return Migration::createTable('member_map_meetups', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('discussion_id')->unique();
    $table->decimal('lat', 7, 2);
    $table->decimal('lng', 7, 2);
    $table->string('label', 150);
    $table->dateTime('starts_at')->index();
    $table->string('timezone', 64)->nullable();
    $table->timestamp('created_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
});
