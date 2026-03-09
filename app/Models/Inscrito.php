<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inscrito extends Model
{
    use HasFactory;
    protected $table = 'inscritos';

    protected $fillable = [
        'entrada',
        'salida'
    ];

    public function checkins()
    {
        return $this->hasMany(Checkin::class);
    }

    public function checkinActual()
    {
        return $this->hasOne(Checkin::class)->latestOfMany();
    }

    public function lastCheckinEntrada()
    {
        return $this->hasOne(Checkin::class)->where('tipo', 'entrada')->latestOfMany();
    }

    public function lastCheckinSalida()
    {
        return $this->hasOne(Checkin::class)->where('tipo', 'salida')->latestOfMany();
    }

}
