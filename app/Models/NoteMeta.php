<?php

namespace MXAbierto\Participa\Models;

use Illuminate\Database\Model\Model;

/**
 * 	Note meta model.
 */
class NoteMeta extends Model
{
    protected $table = 'note_meta';

    const TYPE_USER_ACTION = "user_action";

    public function user()
    {
        return $this->belongsTo('MXAbierto\Participa\Models\User');
    }
}