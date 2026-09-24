# filehutch/filehutch-php

PHP and Laravel client for [FileHutch](https://filehutch.com), file infrastructure for apps that
aren't Netflix. Your app persists an opaque file id (`file_…`). FileHutch owns uploads, private
files, signed URLs, delivery, and named image transforms. Your storage, or FileHutch's, sits
behind it.

```php
use FileHutch\Client;

$hutch = new Client(apiKey: getenv('FILE_HUTCH_API_KEY'));

$file = $hutch->upload(fopen('me.png', 'rb'), policy: 'avatars', filename: 'me.png');

// An MD5 goes with the request, so FileHutch refuses the upload if what arrives is
// not what left. It is read in chunks, so the file is never held in memory to digest
// it. Pass `verify: false` to skip it and check only the byte count.
$file->id;                   // "file_8fK2…"  ← the only thing you store
$file->transforms['avatar']; // a 200×200 URL, if the project defines "avatar"
```

PHP 8.2+. Any PSR-18 HTTP client; Guzzle when none is given, which Laravel already ships. The
Laravel integration registers itself through package discovery and stays out of the way
everywhere else.

## Install

```sh
composer require filehutch/filehutch-php
```

```sh
export FILE_HUTCH_API_KEY=fh_…    # Dashboard → API keys (project-scoped)
export FILE_HUTCH_URL=https://…   # only when not using FileHutch cloud
```

## The API key is server side

The key is scoped to a project and can upload, sign, and delete. **Never ship it to a browser.**
Browser uploads go through your own endpoints, which hold the key and call this client. In
Laravel this package provides them:

```
browser → your server (holds the key) → FileHutch     two small control-plane calls
browser ────────────────────────────→ storage          the bytes, directly
```

## Client

```php
$hutch = new Client(apiKey: $key, url: $url, timeout: 30, httpClient: $psr18, userAgent: 'acme/2.0');

$hutch->project();                        // storage status, policies, transforms
$hutch->transforms();                     // the project's named image sizes
$hutch->file('file_…');
$hutch->signedUrl('file_…', expiresIn: 3600, disposition: 'attachment');
$hutch->transformUrl('file_…', 'avatar', expiresIn: 600);
$hutch->deleteFile('file_…');             // true; the id then reads as status "deleted"

// One call: request, PUT to storage, complete.
$hutch->upload($source, policy: 'documents', filename: 'report.pdf', metadata: ['order_id' => 'ord_1']);

// Or the three steps, explicit:
$created = $hutch->createUpload(policy: 'avatars', filename: 'me.png', contentType: 'image/png', byteSize: strlen($bytes));
$hutch->putToStorage($created->upload, $bytes);
$ready = $hutch->completeUpload($created->upload->fileId);
```

`upload` takes a string of bytes, an open stream resource, a PSR-7 stream, or an `SplFileInfo`,
which includes Laravel's and Symfony's `UploadedFile`. Bytes and streams need a `filename:`; a
file brings its own, and an `UploadedFile` brings the name the user picked. Content type comes from
the `contentType:` argument, then the uploaded file's sniffed type, then the filename's extension.
A pipe or socket is spooled to `php://temp` first, because the bytes are read twice: once for the
digest, once for the PUT.

Responses are readonly objects in camelCase: `File`, `Project`, `UploadPolicy`, `Transform`,
`StorageConnection`, `CreatedUpload`, `UploadAuthorization`, `DeliveryUrl`, `ManifestPage`. Two
things are deliberately **not** rewritten, because the keys are yours and not ours: `metadata`,
and the keys of `$file->transforms`. Every object's `toArray()` returns the payload exactly as the
API sent it.

`File` carries `id filename contentType byteSize checksum visibility status metadata policy
environment storageConnectionId url transforms createdAt updatedAt`, plus `isReady() isPending()
isFailed() isDeleted() isPublic() isPrivate() isImage()`.

`timeout` is applied when the SDK builds its own Guzzle client. PSR-18 has no timeout of its own, so
a client you pass in keeps whatever it was configured with.

## Image transforms

Transforms are **named** in the FileHutch dashboard — `avatar`, `thumb`, `hero` — and your code
only ever says the name. No width, no format, no provider URL syntax, so resizing every avatar in
your app is one dashboard edit and no deploy.

```php
$file = $hutch->file($id);
$file->transforms['avatar'];                 // public images: already on the payload
$hutch->transformUrl($id, 'avatar')->url;    // private images: signed, expires
```

Rendering is a property of the project's storage, not of this SDK. Where it cannot be done you get
a `TransformsUnsupportedError` whose message names what to set up — never a URL that 404s.

## Laravel

The service provider and the `FileHutch` facade are discovered automatically. The client is a
singleton built from `config/filehutch.php`, which reads `FILE_HUTCH_API_KEY`, `FILE_HUTCH_URL`,
`FILE_HUTCH_TIMEOUT` and `FILE_HUTCH_WEBHOOK_SECRET`. Publish it only if you want to change the
direct-upload routes:

```sh
php artisan vendor:publish --tag=filehutch-config
```

```php
use FileHutch\Laravel\Facades\FileHutch;

FileHutch::upload($request->file('report'), policy: 'documents');
app(FileHutch\Client::class)->project();   // or inject FileHutch\Client anywhere
```

### Model

One string column per attachment. Nothing about storage lands in your schema.

```php
Schema::table('users', fn (Blueprint $table) => $table->string('avatar_file_id')->nullable());
```

```php
use FileHutch\Laravel\HasHutch;

class User extends Model
{
    use HasHutch;

    protected function hutches(): array
    {
        return [
            'avatar' => 'avatars',
            'contract' => ['policy' => 'documents', 'dependent' => false],
        ];
    }
}

$user->avatar = $request->file('avatar');  // uploaded file → uploaded to storage on save
$user->avatar = 'file_…';                  // id from a browser direct upload → verified on save
$user->avatar;                             // FileHutch\Resources\File or null (fetched lazily, cached)
$user->hutchAttached('avatar');            // id present, no request
$user->avatar_url;                         // public URL (public policies only)
$user->avatarSignedUrl(expiresIn: 600);    // any file
$user->avatarTransformUrl('thumb');        // a named transform
$user->purgeAvatar();                      // delete remotely, clear the column
```

Options: `column` (default `<name>_file_id`), `dependent` (default `true`: delete the file when the
record is deleted or the attachment is replaced; `false` leaves it) and `verify` (default `true`:
an id assigned from a form must be a ready file uploaded under this policy).

A wrong content type or an oversized file becomes a `ValidationException` on `save()`, keyed by
the attachment name, before anything leaves your server: the policy is checked locally first.
Every attachment on the model is checked before any is uploaded, so one bad field cannot leave
another's bytes orphaned in storage. A replaced file is deleted only after the save succeeds. A
soft delete keeps the file; `forceDelete()` removes it.

The magic methods have plain forms — `hutchFile`, `hutchUrl`, `hutchSignedUrl`,
`hutchTransformUrl`, `purgeHutch` — each taking the attachment name first. Assigning to the
attachment needs it in `$fillable` like any attribute when you mass-assign.

`HasHutch` overrides `getAttribute`, `setAttribute` and `__call`. If another trait on the model
overrides the same methods, resolve them with `insteadof` and call through.

### Browser-direct uploads

The browser talks to your app for the two control-plane calls (your app holds the API key) and
PUTs the bytes straight to storage. The routes are registered for you at the prefix the element
posts to by default:

```
POST /file_hutch/uploads                {policy, filename, content_type, byte_size} → {upload, file}
POST /file_hutch/uploads/{id}/complete                                               → {file}
```

They are **closed** until you say who may upload, in a service provider's `boot`:

```php
use FileHutch\Laravel\DirectUploads;
use Illuminate\Http\Request;

DirectUploads::authorize(fn (Request $request, string $policy) =>
    $request->user() !== null && in_array($policy, ['avatars', 'documents'], true));
```

The authorizer runs on both calls. On the first, `$policy` is the one being requested. On the
second there is no policy in the request, so it is **looked up from the file being finalized** —
never taken from the client, which could otherwise name a policy it likes to finish an upload made
under one it may not use. An authorizer that only takes the request (`fn (Request $request) => …`)
skips that lookup, and so costs nothing extra.

The browser cannot set metadata or a checksum through these routes; only the four fields above are
forwarded. Prefix, middleware (default `web`, so the session and CSRF apply) and whether the
routes exist at all are in `config/filehutch.php` under `direct_uploads`.

### The element

The browser half is `<filehutch-upload>` from the TypeScript SDK's browser bundle
([`@filehutch/sdk/browser`](https://github.com/filehutch/filehutch-typescript#browser-uploads)).
It never sees the API key, and its default endpoint is `/file_hutch/uploads`, which is where the
routes above live — so in Laravel it needs no configuration beyond the policy.

```js
// resources/js/app.js
import { defineUploadElement } from "@filehutch/sdk/browser"
defineUploadElement()
```

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">

<form action="{{ route('profile.update') }}" method="post">
  @csrf
  <filehutch-upload policy="avatars" name="avatar" accept="image/*"></filehutch-upload>
  <button>Save</button>
</form>
```

The element reads the CSRF token from the `csrf-token` meta tag and sends it as `X-CSRF-Token`,
which Laravel accepts. On success its hidden field holds the file id, so the controller is one
line — and `HasHutch` verifies on save that the id is a ready file under the attachment's policy:

```php
if ($request->filled('avatar')) {
    $request->user()->update(['avatar' => $request->input('avatar')]);
}
```

The guard matters: an empty string clears the attachment, and with `dependent` on, deletes the
file. A form submitted without picking a new file should leave the avatar alone.

Submit buttons are disabled while bytes are in flight. The element dispatches `filehutch:start`,
`filehutch:progress`, `filehutch:complete` and `filehutch:error`. Moved the routes? Set
`endpoint="/your/prefix/uploads"` on the element.

## Declarative config and the manifest

A project's upload policies, transforms and environments are a file the API understands. Plan
first, then apply; nothing is deleted unless you ask.

```php
$config = $hutch->config();
$config['uploads']['exports'] = ['types' => ['text/csv'], 'max_size' => '1MB'];

$plan = $hutch->planConfig($config);            // a read-only key can do this
$result = $hutch->applyConfig($config);         // needs a write key; prune: true also deletes
```

`$hutch->manifest(after: $cursor, limit: 500)` pages through every ready file with its object key:
the export path if you ever want to leave.

## Webhooks

FileHutch signs every delivery with `FileHutch-Signature: t=<unix>,v1=<hex>` where
`v1 = HMAC-SHA256(secret, "<t>.<body>")`. Verify against the raw body, then deduplicate on the
event `id` (deliveries are at-least-once):

```php
use FileHutch\Exceptions\SignatureVerificationError;
use FileHutch\Webhook;

Route::post('/webhooks/filehutch', function (Request $request) {
    try {
        $event = Webhook::constructEvent(
            $request->getContent(), $request->header('FileHutch-Signature'), config('filehutch.webhook_secret'),
        );
    } catch (SignatureVerificationError) {
        abort(400);
    }

    if ($event['type'] === 'file.deleted') {
        User::where('avatar_file_id', $event['data']['file']['id'])->update(['avatar_file_id' => null]);
    }

    return response()->noContent();
})->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
```

Signatures older than five minutes are rejected; pass `tolerance:` to change that.

## Errors

Everything thrown extends `FileHutch\Exceptions\FileHutchError`. API failures are an `ApiError`
carrying a stable `errorCode`, the HTTP `status`, and any `details`. Match on the class or
`errorCode`, never the message. (`getCode()` returns the status, because PHP reserves it for an
integer.)

| Class | When |
| --- | --- |
| `ConfigurationError` | no API key, bad URL, or an id that isn't one |
| `ConnectionError` | timeout, DNS, reset |
| `AuthenticationError` | 401 |
| `NotFoundError` | unknown id |
| `InvalidRequestError` → `PolicyError` | bad params; content type or size the policy refuses |
| `StorageNotReadyError` | the project has no verified storage |
| `InvalidStateError` | not ready, already deleted, not public |
| `PermissionError` | 403 / `read_only_key`: the key is read-only and this changes something |
| `ConfigError` | `invalid_config`: the config file has an unknown key, bad size or bad name |
| `PlanLimitError` | 402: the team is out of storage or projects on its plan |
| `TransformError` → `TransformsUnsupportedError` | unknown transform or non-image; storage that cannot render |
| `UploadError` | storage rejected the PUT, upload expired or incomplete, size or checksum mismatch |
| `StorageError` | FileHutch could not reach the bucket |
| `RateLimitError`, `ServerError` | 429, 5xx |

```php
use FileHutch\Exceptions\PolicyError;

try {
    $hutch->upload($bytes, policy: 'avatars', filename: 'huge.png');
} catch (PolicyError $e) {
    return response()->json(['message' => $e->getMessage()], 422);
}
```

## Development

```sh
composer install
composer test        # PHPUnit: the client against a stubbed PSR-18 client, and Laravel via Testbench
```

## License

MIT
