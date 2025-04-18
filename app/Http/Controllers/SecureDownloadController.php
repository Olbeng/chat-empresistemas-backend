<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SecureDownloadController extends Controller
{
    public function downloadFile(Request $request)
    {
        // Validar la petición
        $request->validate([
            'path' => 'required|string',
            'filename' => 'nullable|string'
        ]);

        // Decodificar la ruta que viene en base64
        $path = base64_decode($request->path);

        // Verificar que el archivo existe
        if (!Storage::disk('public')->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo no encontrado'
            ], 404);
        }

        // Usar el nombre proporcionado o generar uno basado en la fecha
        $fileName = $request->filename ?? 'imagen-' . date('Y-m-d-His') . '.jpg';

        // Forzar descarga mostrando el diálogo de guardado
        return Storage::disk('public')->download($path, $fileName, [
            'Content-Type' => Storage::disk('public')->mimeType($path),
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Origin, Content-Type, Accept, Authorization'
        ]);
    }
}
