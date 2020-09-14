<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class LivestreamConfiguration extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'livestream_configurations';

    /**
    * Get the meetings that use the livestream configuration.
    */
   public function meetings()
   {
       return $this->hasMany('App\Meeting', 'livestream_configuration_id');
   }
}
