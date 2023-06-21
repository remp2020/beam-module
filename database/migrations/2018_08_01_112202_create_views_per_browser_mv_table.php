<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Class CreateViewsPerBrowserMvTable
 * Temporary migration, this table works as materialized view
 * TODO: this will go away after conditions in remp#253 issue are specified
 */
class CreateViewsPerBrowserMvTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('views_per_browser_mv', function (Blueprint $table) {
            $table->string('browser_id');
            $table->integer('total_views_last_30_days')->unsigned()->default(0);
            $table->integer('total_views_last_60_days')->unsigned()->default(0);
            $table->integer('total_views_last_90_days')->unsigned()->default(0);
            $table->primary('browser_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('views_per_browser_mv');
    }
}
