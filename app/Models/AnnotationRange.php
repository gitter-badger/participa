<?php

namespace MXAbierto\Participa\Models;

use Illuminate\Database\Eloquent\Model;

class AnnotationRange extends Model
{
    protected $table = "annotation_ranges";
    protected $softDelete = true;
    public $incrementing = false;
    protected $fillable = ['start', 'end', 'start_offset', 'end_offset'];

    public function annotation()
    {
        return $this->belongsTo('MXAbierto\Participa\Models\DBAnnotation');
    }

    public static function firstByRangeOrNew(array $input)
    {
        $retval = static::where('annotation_id', '=', $input['annotation_id'])
                        ->where('start_offset', '=', $input['start_offset'])
                        ->where('end_offset', '=', $input['end_offset'])
                        ->first();

        if (!$retval) {
            $retval = new static();

            foreach ($input as $key => $val) {
                $retval->$key = $val;
            }
        }

        return $retval;
    }
}
