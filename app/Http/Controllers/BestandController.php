<?php

namespace App\Http\Controllers;

use App\Services\GraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'case_id' => 'nullable|integer|exists:cases,id',
            'transactie_soort_id' => 'nullable|integer|exists:transactie_soorten,id',
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

        $this->registerUploadMutationAudit(
            $request,
            $bestandUri,
            $path,
            $originalName,
            $mimeType,
            $file->getSize(),
            isset($validated['case_id']) ? (int) $validated['case_id'] : null,
            isset($validated['transactie_soort_id']) ? (int) $validated['transactie_soort_id'] : null
        );

        return response()->json([
            'bestand_uri' => $bestandUri,
            'storage_key' => $path,
            'bestandsnaam' => $originalName,
            'media_type' => $mimeType,
            'size' => $file->getSize(),
        ]);
    }

    public function view(Request $request)
    {
        $validated = $request->validate([
            'uri' => ['required', 'string'],
        ]);

        $bestandUri = trim((string) $validated['uri']);
        if (! preg_match('/^https?:\/\/[^\s<>"\']+$/', $bestandUri)) {
            abort(404);
        }

        $row = $this->resolveBestandMeta($bestandUri);
        if (! $row) {
            abort(404);
        }

        $storageKey = $row['path'] ?? null;
        if (! is_string($storageKey) || $storageKey === '') {
            abort(404);
        }

        if (str_starts_with($storageKey, 'follow-fix://')) {
            $referenced = trim((string) substr($storageKey, strlen('follow-fix://')));
            $referencedGoicUri = $referenced !== '' ? "http://vwm.voorbeeld.nl/data/goic/{$referenced}" : null;
            $resolvedBestandUri = is_string($referencedGoicUri) ? $this->resolveRealBestandUriForGoic($referencedGoicUri) : null;

            if (is_string($resolvedBestandUri) && $resolvedBestandUri !== '' && $resolvedBestandUri !== $bestandUri) {
                $resolvedMeta = $this->resolveBestandMeta($resolvedBestandUri);
                if ($resolvedMeta && is_string($resolvedMeta['path'] ?? null) && ! str_starts_with((string) $resolvedMeta['path'], 'follow-fix://')) {
                    $bestandUri = $resolvedBestandUri;
                    $row = $resolvedMeta;
                    $storageKey = (string) $resolvedMeta['path'];
                }
            }

            if (str_starts_with($storageKey, 'follow-fix://')) {
                $body = "Dit is een referentiebestand (geen fysiek bestand op schijf).\n\n";
                $body .= "Bestand URI: {$bestandUri}\n";
                if ($referenced !== '') {
                    $body .= "Verwijst naar GOIC: {$referenced}\n";
                }

                return response($body, 200, [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                    'Content-Disposition' => 'inline; filename=\"referentie-volg-goic.txt\"',
                ]);
            }
        }

        $disk = Storage::disk();
        if (! $disk->exists($storageKey)) {
            abort(404);
        }

        $absolutePath = $disk->path($storageKey);
        $fileName = is_string($row['name'] ?? null) && ($row['name'] ?? '') !== ''
            ? (string) $row['name']
            : basename($storageKey);
        $mediaType = is_string($row['media'] ?? null) && ($row['media'] ?? '') !== ''
            ? (string) $row['media']
            : null;

        $headers = [
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $fileName).'"',
        ];
        if ($mediaType) {
            $headers['Content-Type'] = $mediaType;
        }

        return response()->file($absolutePath, $headers);
    }

    private function registerUploadMutationAudit(
        Request $request,
        string $bestandUri,
        ?string $storageKey,
        string $bestandsnaam,
        ?string $mediaType,
        int $size,
        ?int $requestedCaseId,
        ?int $requestedTransactieSoortId
    ): void {
        $user = $request->user();
        $userId = is_object($user) ? ($user->id ?? null) : null;
        if (! is_int($userId)) {
            return;
        }

        $case = null;
        if (is_int($requestedCaseId)) {
            $case = DB::table('cases')
                ->where('id', $requestedCaseId)
                ->where('user_id', $userId)
                ->first(['id', 'case_soort_id']);
        }

        if (! $case) {
            return;
        }

        $transactieSoortId = $requestedTransactieSoortId;
        if (! is_int($transactieSoortId)) {
            $transactieSoortId = DB::table('case_soort_transactie')
                ->where('case_soort_id', (int) $case->case_soort_id)
                ->orderBy('volgorde')
                ->value('transactie_soort_id');
        }

        if (! is_int($transactieSoortId)) {
            $transactieSoortId = DB::table('transactie_soorten')->orderBy('id')->value('id');
        }

        if (! is_int($transactieSoortId)) {
            return;
        }

        $now = now();
        DB::transaction(function () use ($case, $transactieSoortId, $userId, $now, $bestandUri, $storageKey, $bestandsnaam, $mediaType, $size) {
            $transactieId = DB::table('transacties')->insertGetId([
                'case_id' => (int) $case->id,
                'transactie_soort_id' => (int) $transactieSoortId,
                'user_id' => (int) $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('object_mutaties')->insert([
                'transactie_id' => $transactieId,
                'sjabloon_uri' => 'http://ontologie.politie.nl/def/vwm#Bestand',
                'data' => json_encode([
                    'actie' => 'bestand_upload',
                    'bestand_uri' => $bestandUri,
                    'bestandsnaam' => $bestandsnaam,
                    'media_type' => $mediaType,
                    'storage_key' => $storageKey,
                    'size' => $size,
                ], JSON_UNESCAPED_SLASHES),
                'object_uri' => $bestandUri,
                'gegevens_object_in_context_id' => null,
                'geproduceerde_toestand_id' => null,
                'verwijderde_toestand_id' => null,
                'datum_tijd' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function resolveBestandMeta(string $bestandUri): ?array
    {
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?path ?name ?media
            WHERE {
                <{$bestandUri}> a vwm:Bestand .
                OPTIONAL { <{$bestandUri}> vwm:externeOpslagIdentificatie ?path . }
                OPTIONAL { <{$bestandUri}> vwm:bestandsnaam ?name . }
                OPTIONAL { <{$bestandUri}> vwm:mediaType ?media . }
            }
            LIMIT 1
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (\Throwable) {
            return null;
        }

        $row = $rows[0] ?? null;

        return is_array($row) ? $row : null;
    }

    private function resolveRealBestandUriForGoic(string $goicUri): ?string
    {
        $query = "
            PREFIX vwm: <http://ontologie.politie.nl/def/vwm#>
            SELECT ?bestand ?at
            WHERE {
                ?tb vwm:beschrijftGOIC <{$goicUri}> ;
                    vwm:heeftBestand ?bestand .
                OPTIONAL { ?tb vwm:geregistreerdOp ?at . }
            }
            ORDER BY DESC(?at)
            LIMIT 5
        ";

        try {
            $rows = $this->graphService->query($query);
        } catch (\Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            $candidate = $row['bestand'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
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
