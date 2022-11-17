<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'Справочники';

    protected $fillable = [
        'Название', 'Данные', 'user_id', 'Справочники_списки_id'
    ];
}
