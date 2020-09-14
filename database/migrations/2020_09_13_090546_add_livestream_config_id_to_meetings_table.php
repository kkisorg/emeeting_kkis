<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddLivestreamConfigIdToMeetingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->unsignedBigInteger('livestream_configuration_id')->after('zoom_redirection_url_disable_at')->nullable();

            $table->foreign('livestream_configuration_id')->references('id')->on('livestream_configurations');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropForeign('meetings_livestream_configuration_id_foreign');
            $table->dropColumn('livestream_configuration_id');
        });
    }
}
