<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Message; // Importas el modelo de Message

class Contact extends Model
{
    use HasFactory;

    // Especifica los campos que son asignables
    protected $fillable = ['phone_number', 'name', 'user_id'];

    // Define la relación con el modelo User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    // En el modelo Contact
    public function messages()
    {
        return $this->hasMany(Message::class);
    }
    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latest();
    }
}
