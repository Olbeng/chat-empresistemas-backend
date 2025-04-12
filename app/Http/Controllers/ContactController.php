<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Contact;

class ContactController extends Controller
{
    // Constructor donde aplicamos el middleware a todo el controlador
    public function __construct()
    {
        // Aplica el middleware 'auth:api' para todas las rutas de este controlador
        $this->middleware('jwt.auth');
    }

    // Método para obtener todos los contactos
    public function index($userId)
    {
        $contacts = Contact::where('user_id', $userId)
            ->withCount(['messages as received_messages_count' => function ($query) {
                $query->where('status', 'received');
            }])
            ->with('latestMessage:id,contact_id,created_at')
            ->get();

        $contacts = $contacts->map(function ($contact) {
            $contact->lastMessageTime = $contact->latestMessage
                ? $contact->latestMessage->created_at->addHours(6)->toIso8601String()
                : null;
            unset($contact->latestMessage);
            return $contact;
        });

        // Ordenar los contactos después del mapeo
        $sortedContacts = $contacts->sortByDesc(function ($contact) {
            return $contact->lastMessageTime ? $contact->lastMessageTime : '';
        })->values(); // values() reindexa el array

        return response()->json($sortedContacts);
    }
}
