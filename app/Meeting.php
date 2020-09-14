<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'meetings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['meeting_id', 'topic', 'start_at', 'duration', 'zoom_url', 'status'];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'start_at',
        'zoom_redirection_url_enable_at',
        'zoom_redirection_url_disable_at',
        'livestream_start_at',
        'livestream_redirection_url_enable_at',
        'livestream_redirection_url_disable_at',
    ];

    /**
     * Get the livestream configuration.
     */
    public function livestream_configurations()
    {
        return $this->belongsTo('App\LivestreamConfiguration', 'livestream_configuration_id');
    }
}
