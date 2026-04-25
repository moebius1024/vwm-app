<?php

namespace App\Http\Controllers;

use App\Services\GraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BestandController extends Controller
{
    protected GraphService $graphService;

    public function __construct(GraphService $graphService)
    {
        $this->graphService = $graphService;
    }

    public function upload(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|max:51200', // max 50MB
        ]);

        $file = $validated['file'];
        $uuid = (string) Str::uuid();
        $originalName = $file->getClientOriginalName() ?: 'bestand';
        $extension = $file->getClientOriginalExtension();
        $storedName = $uuid.($extension ? ".{$extension}" : '');
        $disk = Storage::disk();
        $path = $disk->putFileAs('uploads/bestanden', $file, $storedName);

        $bestandUri = "http://vwm.voorbeeld.nl/data/bestand/{$uuid}";
        $mimeType = $file->getClientMimeType() ?: $file->getMimeType();

        $triples = '';
        $triples .= "<{$bestandUri}> a <http://ontologie.politie.nl/def/vwm#Bestand> .\n";
        if (! empty($mimeType)) {
            $triples .= "<{$bestandUri}> <http://ontologie.politie.nl/def/vwm#mediaType> \"{$this->escapeLiteral($mimeType)}\" .\n";
        }
        if (! empty($originalName)) {
            $triples .= "<{$bestandUri}> <http://ontologie.politie.nl/def/vwm#bestandsnaam> \"{$this->escapeLiteral($originalName)}\" .\n";
        }
        if (! empty($path)) {
            $triples .= "<{$bestandUri}> <http://ontologie.politie.nl/def/vwm#externeOpslagIdentificatie> \"{$this->escapeLiteral($path)}\" .\n";
        }

        $sparql = "
            INSERT DATA {
                GRAPH <http://vwm.voorbeeld.nl/data/onderzoek> {
                    {$triples}
                }
            }
        ";

        try {
            $this->graphService->update($sparql);
        } catch (\Exception $e) {
            if ($path) {
                $disk->delete($path);
            }

            return response()->json([
                'error' => 'GraphDB update mislukt: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'bestand_uri' => $bestandUri,
            'storage_key' => $path,
            'bestandsnaam' => $originalName,
            'media_type' => $mimeType,
            'size' => $file->getSize(),
        ]);
    }

    private function escapeLiteral(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value
        );
    }
}
