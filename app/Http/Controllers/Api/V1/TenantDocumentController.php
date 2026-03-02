<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantDocumentController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function listFolders(): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        if (! Schema::hasTable('tenant_document_folders')) {
            return response()->json(['message' => 'Missing tenant_document_folders table.'], 422);
        }

        $folders = DB::table('tenant_document_folders as f')
            ->leftJoin('tenant_documents as d', 'd.folder_id', '=', 'f.id')
            ->where('f.tenant_id', $tenantId)
            ->groupBy('f.id', 'f.tenant_id', 'f.name', 'f.created_by_user_id', 'f.created_at', 'f.updated_at')
            ->orderBy('f.name')
            ->get([
                'f.id',
                'f.tenant_id',
                'f.name',
                'f.created_by_user_id',
                'f.created_at',
                'f.updated_at',
                DB::raw('COUNT(d.id) as document_count'),
            ]);

        return response()->json(['folders' => $folders]);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        if (! Schema::hasTable('tenant_document_folders')) {
            return response()->json(['message' => 'Missing tenant_document_folders table.'], 422);
        }

        $id = DB::table('tenant_document_folders')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => trim((string) $data['name']),
            'created_by_user_id' => $request->user()?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'document_folder_created',
            'module' => 'documents',
            'entity_id' => (string) $id,
            'entity_type' => 'tenant_document_folder',
            'new_value' => ['id' => $id, 'name' => trim((string) $data['name'])],
        ]);

        return response()->json(['id' => $id, 'message' => 'OK']);
    }

    public function updateFolder(Request $request, int $folderId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        if (! Schema::hasTable('tenant_document_folders')) {
            return response()->json(['message' => 'Missing tenant_document_folders table.'], 422);
        }

        $updated = DB::table('tenant_document_folders')
            ->where('id', $folderId)
            ->where('tenant_id', $tenantId)
            ->update([
                'name' => trim((string) $data['name']),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return response()->json(['message' => 'Folder not found.'], 404);
        }

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'document_folder_updated',
            'module' => 'documents',
            'entity_id' => (string) $folderId,
            'entity_type' => 'tenant_document_folder',
            'new_value' => ['id' => $folderId, 'name' => trim((string) $data['name'])],
        ]);

        return response()->json(['message' => 'OK']);
    }

    public function deleteFolder(Request $request, int $folderId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        if (! Schema::hasTable('tenant_document_folders') || ! Schema::hasTable('tenant_documents')) {
            return response()->json(['message' => 'Missing tenant document tables.'], 422);
        }

        $docs = DB::table('tenant_documents')
            ->where('folder_id', $folderId)
            ->where('tenant_id', $tenantId)
            ->get(['id', 'path']);

        foreach ($docs as $doc) {
            $path = (string) ($doc->path ?? '');
            if ($path !== '' && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }

        if (Schema::hasTable('tenant_document_riesgo_trs')) {
            $docIds = $docs->pluck('id')->all();
            if (count($docIds) > 0) {
                DB::table('tenant_document_riesgo_trs')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('document_id', $docIds)
                    ->delete();
            }
        }

        DB::table('tenant_documents')
            ->where('folder_id', $folderId)
            ->where('tenant_id', $tenantId)
            ->delete();

        $deleted = DB::table('tenant_document_folders')
            ->where('id', $folderId)
            ->where('tenant_id', $tenantId)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Folder not found.'], 404);
        }

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'document_folder_deleted',
            'module' => 'documents',
            'entity_id' => (string) $folderId,
            'entity_type' => 'tenant_document_folder',
            'previous_value' => ['id' => $folderId, 'documents' => $docs->pluck('id')->all()],
        ]);

        return response()->json(['message' => 'OK']);
    }

    public function listDocuments(Request $request, int $folderId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        if (! Schema::hasTable('tenant_documents') || ! Schema::hasTable('tenant_document_folders')) {
            return response()->json(['message' => 'Missing tenant document tables.'], 422);
        }

        $exists = DB::table('tenant_document_folders')
            ->where('id', $folderId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            return response()->json(['message' => 'Folder not found.'], 404);
        }

        $docs = DB::table('tenant_documents as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by_user_id')
            ->where('d.folder_id', $folderId)
            ->where('d.tenant_id', $tenantId)
            ->orderBy('d.created_at', 'desc')
            ->get([
                'd.id',
                'd.folder_id',
                'd.name',
                'd.original_name',
                'd.size_bytes',
                'd.mime_type',
                'd.extension',
                'd.created_at',
                'u.name as uploader_name',
            ]);

        $riskMap = [];
        if (Schema::hasTable('tenant_document_riesgo_trs') && $docs->count() > 0) {
            $docIds = $docs->pluck('id')->all();
            $rows = DB::table('tenant_document_riesgo_trs')
                ->where('tenant_id', $tenantId)
                ->whereIn('document_id', $docIds)
                ->get(['document_id', 'riesgo_id']);
            foreach ($rows as $row) {
                $docId = (int) ($row->document_id ?? 0);
                if (! $docId) {
                    continue;
                }
                $riskMap[$docId] = $riskMap[$docId] ?? [];
                $riskMap[$docId][] = (string) ($row->riesgo_id ?? '');
            }
        }

        foreach ($docs as $doc) {
            $docId = (int) ($doc->id ?? 0);
            $doc->risk_ids = $riskMap[$docId] ?? [];
        }

        return response()->json(['documents' => $docs]);
    }

    public function uploadDocuments(Request $request, int $folderId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        @ini_set('upload_max_filesize', '50M');
        @ini_set('post_max_size', '60M');
        @ini_set('max_file_uploads', '20');

        if (! Schema::hasTable('tenant_documents') || ! Schema::hasTable('tenant_document_folders')) {
            return response()->json(['message' => 'Missing tenant document tables.'], 422);
        }

        $exists = DB::table('tenant_document_folders')
            ->where('id', $folderId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            return response()->json(['message' => 'Folder not found.'], 404);
        }

        $contentLength = (int) ($request->server('CONTENT_LENGTH') ?? 0);
        if (! $request->hasFile('files') && $contentLength > 0) {
            $uploadLimit = (string) (ini_get('upload_max_filesize') ?: '');
            $postLimit = (string) (ini_get('post_max_size') ?: '');

            return response()->json([
                'message' => 'Upload failed. Check PHP limits upload_max_filesize and post_max_size.',
                'upload_max_filesize' => $uploadLimit,
                'post_max_size' => $postLimit,
                'content_length' => $contentLength,
            ], 422);
        }

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:51200', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,jpg,jpeg,png'],
            'names' => ['required', 'array', 'min:1'],
            'names.*' => ['required', 'string', 'max:255'],
            'risk_ids' => ['sometimes', 'array'],
            'risk_ids.*' => ['nullable'],
            'risk_ids.*.*' => ['string', 'max:255'],
        ]);

        $files = $data['files'] ?? [];
        $names = $data['names'] ?? [];
        $riskIds = $data['risk_ids'] ?? [];
        $riskIdsByFile = [];
        if (is_array($riskIds)) {
            $hasNested = false;
            foreach ($riskIds as $entry) {
                if (is_array($entry)) {
                    $hasNested = true;
                    break;
                }
            }
            if ($hasNested) {
                $riskIdsByFile = $riskIds;
            } else {
                $flat = array_values(array_unique(array_filter(array_map('strval', $riskIds), fn ($v) => trim($v) !== '')));
                if (! empty($flat)) {
                    foreach (array_keys($files) as $idx) {
                        $riskIdsByFile[$idx] = $flat;
                    }
                }
            }
        }

        if (count($files) !== count($names)) {
            return response()->json(['message' => 'Names count does not match files count.'], 422);
        }

        $created = [];
        foreach ($files as $idx => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $name = trim((string) ($names[$idx] ?? ''));
            if ($name === '') {
                return response()->json(['message' => 'Document name is required.'], 422);
            }

            $original = $file->getClientOriginalName() ?: 'documento';
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original) ?: 'documento';
            $uuid = Str::uuid()->toString();
            $dir = 'tenant_docs/'.$tenantId.'/'.$folderId;
            $storedName = $uuid.'-'.$safe;
            $path = Storage::disk('local')->putFileAs($dir, $file, $storedName);
            $extension = strtolower($file->getClientOriginalExtension() ?: '');
            $mimeType = $file->getClientMimeType();

            $id = DB::table('tenant_documents')->insertGetId([
                'tenant_id' => $tenantId,
                'folder_id' => $folderId,
                'name' => $name,
                'original_name' => $original,
                'stored_name' => $storedName,
                'path' => $path,
                'size_bytes' => $file->getSize(),
                'mime_type' => $mimeType,
                'extension' => $extension,
                'uploaded_by_user_id' => $request->user()?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created[] = $id;

            if (Schema::hasTable('tenant_document_riesgo_trs')) {
                $riskList = $riskIdsByFile[$idx] ?? [];
                if (! is_array($riskList)) {
                    $riskList = [$riskList];
                }
                $riskList = array_values(array_unique(array_filter(array_map('strval', $riskList), fn ($v) => trim($v) !== '')));
                foreach ($riskList as $riskId) {
                    DB::table('tenant_document_riesgo_trs')->insert([
                        'tenant_id' => $tenantId,
                        'document_id' => $id,
                        'riesgo_id' => $riskId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (! empty($created)) {
            $this->auditLogger->logFromRequest($request, [
                'event_type' => 'document_uploaded',
                'module' => 'documents',
                'entity_id' => (string) $folderId,
                'entity_type' => 'tenant_document',
                'new_value' => ['folder_id' => $folderId, 'document_ids' => $created],
            ]);
        }

        return response()->json(['message' => 'OK', 'ids' => $created]);
    }

    public function updateDocument(Request $request, int $documentId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'risk_ids' => ['sometimes', 'array'],
            'risk_ids.*' => ['nullable', 'string', 'max:255'],
        ]);

        if (! Schema::hasTable('tenant_documents')) {
            return response()->json(['message' => 'Missing tenant_documents table.'], 422);
        }

        $exists = DB::table('tenant_documents')
            ->where('id', $documentId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        DB::table('tenant_documents')
            ->where('id', $documentId)
            ->where('tenant_id', $tenantId)
            ->update([
                'name' => trim((string) $data['name']),
                'updated_at' => now(),
            ]);

        if (array_key_exists('risk_ids', $data) && Schema::hasTable('tenant_document_riesgo_trs')) {
            DB::table('tenant_document_riesgo_trs')
                ->where('tenant_id', $tenantId)
                ->where('document_id', $documentId)
                ->delete();
            $riskList = $data['risk_ids'] ?? [];
            if (! is_array($riskList)) {
                $riskList = [$riskList];
            }
            $riskList = array_values(array_unique(array_filter(array_map('strval', $riskList), fn ($v) => trim($v) !== '')));
            foreach ($riskList as $riskId) {
                DB::table('tenant_document_riesgo_trs')->insert([
                    'tenant_id' => $tenantId,
                    'document_id' => $documentId,
                    'riesgo_id' => $riskId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'document_updated',
            'module' => 'documents',
            'entity_id' => (string) $documentId,
            'entity_type' => 'tenant_document',
            'new_value' => ['id' => $documentId, 'name' => trim((string) $data['name']), 'risk_ids' => $data['risk_ids'] ?? []],
        ]);

        return response()->json(['message' => 'OK']);
    }

    public function deleteDocument(Request $request, int $documentId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        if (! Schema::hasTable('tenant_documents')) {
            return response()->json(['message' => 'Missing tenant_documents table.'], 422);
        }

        $doc = DB::table('tenant_documents')
            ->where('id', $documentId)
            ->where('tenant_id', $tenantId)
            ->first(['path', 'name']);

        if (! $doc) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $path = (string) ($doc->path ?? '');
        if ($path !== '' && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }

        if (Schema::hasTable('tenant_document_riesgo_trs')) {
            DB::table('tenant_document_riesgo_trs')
                ->where('tenant_id', $tenantId)
                ->where('document_id', $documentId)
                ->delete();
        }

        DB::table('tenant_documents')
            ->where('id', $documentId)
            ->where('tenant_id', $tenantId)
            ->delete();

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'document_deleted',
            'module' => 'documents',
            'entity_id' => (string) $documentId,
            'entity_type' => 'tenant_document',
            'previous_value' => [
                'id' => $documentId,
                'name' => $doc?->name ?? null,
                'path' => $doc?->path ?? null,
            ],
        ]);

        return response()->json(['message' => 'OK']);
    }

    public function downloadDocument(Request $request, int $documentId): BinaryFileResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            abort(422, __('messages.tenant.missing'));
        }

        if (! Schema::hasTable('tenant_documents')) {
            abort(422, 'Missing tenant_documents table.');
        }

        $doc = DB::table('tenant_documents')
            ->where('id', $documentId)
            ->where('tenant_id', $tenantId)
            ->first(['path', 'original_name']);

        if (! $doc) {
            abort(404, 'Document not found.');
        }

        $path = (string) ($doc->path ?? '');
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404, 'File missing.');
        }

        $filename = (string) ($doc->original_name ?? 'documento');
        $absolutePath = Storage::disk('local')->path($path);

        return response()->download($absolutePath, $filename);
    }
}
