<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Http\Requests\Bapi\Users\CreateUserRequest;
use App\Http\Requests\Bapi\Users\ProfileImageRequest;
use App\Http\Requests\Bapi\Users\UpdateMetadataRequest;
use App\Http\Requests\Bapi\Users\UpdateUserRequest;
use App\Http\Requests\Bapi\Users\VerifyPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Session;
use App\Models\User;
use App\Services\Sessions\SessionLifecycle;
use App\Support\Idempotency;
use App\Support\IdempotencyMismatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * BAPI users surface (PLAN §3.1, OA-2). Server-to-server CRUD plus the
 * imperative actions the dashboard invokes on the operator's behalf.
 *
 *   GET    /v1/users                  list w/ filters + pagination + sort
 *   GET    /v1/users/count            scalar count under the same filter set
 *   POST   /v1/users                  create (supports password_digest import)
 *   GET    /v1/users/{id}
 *   PATCH  /v1/users/{id}
 *   DELETE /v1/users/{id}             soft-delete + revoke every session
 *   POST   /v1/users/{id}/ban|unban|lock|unlock
 *   POST   /v1/users/{id}/profile_image
 *   DELETE /v1/users/{id}/profile_image
 *   PATCH  /v1/users/{id}/metadata
 *   POST   /v1/users/{id}/verify_password
 */
final class UsersController
{
    private const IMAGE_DISK = 's3';

    private const ALLOWED_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(private readonly SessionLifecycle $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = User::query()->withoutGlobalScopes()->where('environment_id', $env->id);

        $this->applyUserFilters($query, $request);

        $orderBy = (string) $request->input('order_by', '-created_at');
        $direction = str_starts_with($orderBy, '-') ? 'desc' : 'asc';
        $column = ltrim($orderBy, '-+');
        $allowed = ['created_at', 'updated_at', 'last_active_at', 'last_sign_in_at'];
        $query->orderBy(in_array($column, $allowed, true) ? $column : 'created_at', $direction);

        $limit = max(1, min(500, (int) $request->input('limit', 10)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (User $u) => UserResource::from($u, includePrivate: true))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function count(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = User::query()->withoutGlobalScopes()->where('environment_id', $env->id);
        $this->applyUserFilters($query, $request);

        return response()->json(['object' => 'total_count', 'total_count' => $query->count()]);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $env = app(Environment::class);
        $idempotencyKey = $request->header('Idempotency-Key');

        try {
            $payload = Idempotency::cache(
                envId: $env->id,
                key: is_string($idempotencyKey) ? $idempotencyKey : null,
                requestHash: Idempotency::hashRequest($request->method(), $request->path(), $request->all()),
                fn: fn () => $this->createUserPayload($env, $request),
            );
        } catch (IdempotencyMismatch $e) {
            return $this->error(422, Idempotency::ERROR_CODE_MISMATCH, $e->getMessage());
        }

        return response()->json($payload['body'], $payload['status']);
    }

    public function show(string $id): JsonResponse
    {
        $user = $this->find($id);
        if ($user === null) {
            return $this->error(404, 'user_not_found', 'No user matches that id in this environment.');
        }

        return response()->json(UserResource::from($user, includePrivate: true))
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = $this->find($id);
        if ($user === null) {
            return $this->error(404, 'user_not_found', 'No user matches that id in this environment.');
        }
        foreach (['external_id', 'username', 'first_name', 'last_name', 'image_url', 'locale', 'primary_email_address_id'] as $field) {
            if ($request->has($field)) {
                $user->{$field} = $request->input($field);
            }
        }
        foreach (['public_metadata', 'private_metadata', 'unsafe_metadata'] as $blob) {
            if ($request->has($blob) && is_array($request->input($blob))) {
                $user->{$blob} = $request->input($blob);
            }
        }
        if ($request->has('delete_self_enabled')) {
            $user->delete_self_enabled = $request->boolean('delete_self_enabled');
        }
        $user->save();

        return response()->json(UserResource::from($user->fresh(), includePrivate: true));
    }

    public function destroy(string $id): JsonResponse
    {
        $user = $this->find($id);
        if ($user === null) {
            return $this->error(404, 'user_not_found', 'No user matches that id in this environment.');
        }
        $this->revokeAllSessions($user);
        $user->delete();

        return response()->json(['object' => 'deleted_object', 'id' => $id, 'deleted' => true]);
    }

    public function ban(string $id): JsonResponse
    {
        return $this->setUserFlags($id, ['banned' => true]);
    }

    public function unban(string $id): JsonResponse
    {
        return $this->setUserFlags($id, ['banned' => false]);
    }

    public function lock(Request $request, string $id): JsonResponse
    {
        $minutes = (int) $request->input('lockout_minutes', 60);
        $expires = $minutes > 0 ? now()->addMinutes($minutes) : null;

        return $this->setUserFlags($id, ['locked' => true, 'lockout_expires_at' => $expires]);
    }

    public function unlock(string $id): JsonResponse
    {
        return $this->setUserFlags($id, ['locked' => false, 'lockout_expires_at' => null]);
    }

    public function updateMetadata(UpdateMetadataRequest $request, string $id): JsonResponse
    {
        $user = $this->find($id);
        if ($user === null) {
            return $this->error(404, 'user_not_found', 'No user matches that id in this environment.');
        }

        // Per OA-2 metadata patch: shallow-merge each blob.
        foreach (['public_metadata', 'private_metadata', 'unsafe_metadata'] as $blob) {
            if ($request->has($blob) && is_array($request->input($blob))) {
                $existing = is_array($user->{$blob}) ? $user->{$blob} : [];
                $user->{$blob} = array_merge($existing, $request->input($blob));
            }
        }
        $user->save();

        return response()->json(UserResource::from($user->fresh(), includePrivate: true));
    }

    public function verifyPassword(VerifyPasswordRequest $request, string $id): JsonResponse
    {
        $user = $this->find($id);
        if ($user === null) {
            return $this->error(404, 'user_not_found', 'No user matches that id in this environment.');
        }
        $verified = $user->password_hash !== null && Hash::check((string) $request->input('password'), $user->password_hash);

        return response()->json(['object' => 'verify_password', 'verified' => $verified]);
    }

    public function uploadProfileImage(ProfileImageRequest $request, string $id): JsonResponse
    {
        $user = $this->find($id);
        if ($user === null) {
            return $this->error(404, 'user_not_found', 'No user matches that id in this environment.');
        }
        $file = $request->file('file');
        if ($file === null) {
            return $this->error(422, 'form_param_nil', 'A file is required.');
        }

        // Validate via the actual bytes — per AC, never trust the supplied
        // MIME type. getimagesize() peeks at the magic bytes.
        $info = @getimagesize($file->getRealPath());
        $detectedMime = is_array($info) ? ($info['mime'] ?? null) : null;
        if (! is_string($detectedMime) || ! in_array($detectedMime, self::ALLOWED_IMAGE_MIMES, true)) {
            return $this->error(422, 'form_param_format_invalid', 'File must be one of: '.implode(', ', self::ALLOWED_IMAGE_MIMES));
        }

        $disk = Storage::disk(self::IMAGE_DISK);
        $extension = $file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'png';
        $path = "user-images/{$user->environment_id}/{$user->id}.{$extension}";
        $disk->put($path, file_get_contents($file->getRealPath()), 'public');
        $url = method_exists($disk, 'url') ? $disk->url($path) : $path;

        $user->forceFill(['image_url' => $url, 'has_image' => true])->save();

        return response()->json(UserResource::from($user->fresh(), includePrivate: true));
    }

    public function deleteProfileImage(string $id): JsonResponse
    {
        $user = $this->find($id);
        if ($user === null) {
            return $this->error(404, 'user_not_found', 'No user matches that id in this environment.');
        }
        if ($user->image_url !== null) {
            // Best-effort delete; if the path doesn't resolve cleanly we move
            // on (the row update is the source of truth).
            $disk = Storage::disk(self::IMAGE_DISK);
            $candidates = [
                "user-images/{$user->environment_id}/{$user->id}.png",
                "user-images/{$user->environment_id}/{$user->id}.jpg",
                "user-images/{$user->environment_id}/{$user->id}.jpeg",
                "user-images/{$user->environment_id}/{$user->id}.webp",
            ];
            foreach ($candidates as $path) {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        }
        $user->forceFill(['image_url' => null, 'has_image' => false])->save();

        return response()->json(UserResource::from($user->fresh(), includePrivate: true));
    }

    /* -------------------- helpers -------------------- */

    private function createUserPayload(Environment $env, CreateUserRequest $request): array
    {
        $emails = $request->input('email_addresses', []);
        if (is_array($emails) && ! empty($emails)) {
            $existing = EmailAddress::query()
                ->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->whereIn('email_address', array_map('strtolower', $emails))
                ->exists();
            if ($existing) {
                return [
                    'status' => 422,
                    'body' => $this->errorBody('form_identifier_exists', 'One or more email addresses are already registered in this environment.'),
                ];
            }
        }

        $hashFromImport = null;
        $passwordImported = false;
        $passwordHasher = null;
        $digest = $request->input('password_digest');
        if (is_string($digest) && $digest !== '') {
            $hasher = (string) $request->input('password_hasher', 'bcrypt');
            $hashFromImport = $this->canonicalizeImportedHash($digest, $hasher);
            $passwordImported = true;
            $passwordHasher = $hasher;
        }

        $user = new User([
            'environment_id' => $env->id,
            'external_id' => $request->input('external_id'),
            'username' => $request->input('username'),
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'image_url' => $request->input('image_url'),
            'locale' => $request->input('locale'),
            'public_metadata' => $request->input('public_metadata') ?? [],
            'private_metadata' => $request->input('private_metadata') ?? [],
            'unsafe_metadata' => $request->input('unsafe_metadata') ?? [],
        ]);
        if ($hashFromImport !== null) {
            $user->password_hash = $hashFromImport;
            $user->password_imported = true;
            $user->password_hasher = $passwordHasher;
        } elseif (is_string($request->input('password')) && $request->input('password') !== '') {
            $user->setPassword((string) $request->input('password'));
        }
        $user->save();

        if (is_array($emails)) {
            $first = true;
            foreach ($emails as $address) {
                $row = EmailAddress::query()->withoutGlobalScopes()->create([
                    'environment_id' => $env->id,
                    'user_id' => $user->id,
                    'email_address' => $address,
                    'is_primary' => $first,
                    'verified_at' => $first ? now() : null,
                ]);
                if ($first) {
                    $user->forceFill(['primary_email_address_id' => $row->id])->saveQuietly();
                    $first = false;
                }
            }
        }

        return [
            'status' => 201,
            'body' => UserResource::from($user->fresh(), includePrivate: true),
        ];
    }

    private function applyUserFilters($query, Request $request): void
    {
        if ($request->has('email_address')) {
            $emails = (array) $request->input('email_address');
            $query->whereExists(function ($q) use ($emails): void {
                $q->select('id')->from('email_addresses')
                    ->whereColumn('email_addresses.user_id', 'users.id')
                    ->whereIn('email_addresses.email_address', array_map('strtolower', $emails));
            });
        }
        if ($request->has('external_id')) {
            $query->whereIn('external_id', (array) $request->input('external_id'));
        }
        if ($request->has('username')) {
            $query->whereIn('username', (array) $request->input('username'));
        }
        if ($request->has('user_id')) {
            $query->whereIn('id', (array) $request->input('user_id'));
        }
        if ($request->has('query')) {
            $needle = '%'.(string) $request->input('query').'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('first_name', 'like', $needle)
                    ->orWhere('last_name', 'like', $needle)
                    ->orWhere('username', 'like', $needle);
            });
        }
        foreach (['banned', 'locked'] as $flag) {
            if ($request->has($flag)) {
                $query->where($flag, $request->boolean($flag));
            }
        }
    }

    private function setUserFlags(string $id, array $flags): JsonResponse
    {
        $user = $this->find($id);
        if ($user === null) {
            return $this->error(404, 'user_not_found', 'No user matches that id in this environment.');
        }
        $user->forceFill($flags)->save();
        if (($flags['banned'] ?? false) === true || ($flags['locked'] ?? false) === true) {
            $this->revokeAllSessions($user);
        }

        return response()->json(UserResource::from($user->fresh(), includePrivate: true));
    }

    private function find(string $id): ?User
    {
        $env = app(Environment::class);

        return User::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $id)
            ->first();
    }

    private function revokeAllSessions(User $user): void
    {
        Session::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('status', Session::LIVE_STATUSES)
            ->each(fn (Session $s) => $this->sessions->revoke($s));
    }

    private function canonicalizeImportedHash(string $digest, string $hasher): string
    {
        // Argon2id and bcrypt hashes already carry their parameters in the
        // string itself — Laravel's Hash::check matches them as long as the
        // active driver supports them. pbkdf2_sha256 / scrypt aren't natively
        // supported by Hash; we store them with a `$pbkdf2-sha256$` /
        // `$scrypt$` prefix and a custom verifier ships in AU-18.
        if (in_array($hasher, ['pbkdf2_sha256', 'scrypt'], true) && ! str_starts_with($digest, '$')) {
            return '$'.str_replace('_', '-', $hasher).'$'.$digest;
        }

        return $digest;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json($this->errorBody($code, $message), $status);
    }

    private function errorBody(string $code, string $message): array
    {
        return [
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ];
    }
}
