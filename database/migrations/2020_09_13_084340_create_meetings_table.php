<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMeetingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('meeting_id');
            $table->string('topic');
            $table->timestamp('start_at')->nullable();
            $table->integer('duration');
            $table->string('zoom_url');
            $table->enum('status', ['ENABLED', 'DISABLED'])->default('ENABLED');

            $table->string('zoom_redirection_url')->nullable();
            $table->timestamp('zoom_redirection_url_enable_at')->nullable();
            $table->timestamp('zoom_redirection_url_disable_at')->nullable();

            $table->string('livestream_url')->nullable();
            $table->timestamp('livestream_start_at')->nullable();
            $table->string('livestream_redirection_url')->nullable();
            $table->timestamp('livestream_redirection_url_enable_at')->nullable();
            $table->timestamp('livestream_redirection_url_disable_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('meetings');
    }
}
