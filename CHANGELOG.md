# Changelog

## 0.1.0 — Unreleased

First release.

### Client

- `FileHutch\Client`: project, transforms, file, uploads (create, complete, and a one-call
  `upload` that PUTs straight to storage), signed URLs, transform URLs, delete, declarative
  config (`config`, `planConfig`, `applyConfig`) and the manifest. The same surface as the
  TypeScript SDK, in PHP's named arguments.
- `upload` sends an MD5 of the bytes, read in chunks, so FileHutch refuses an upload that arrives
  corrupted. `verify: false` skips it. Accepts bytes, stream resources, PSR-7 streams and
  `SplFileInfo` (including `UploadedFile`).
- Any PSR-18 client; Guzzle by default, with the timeout applied.
- The API's error codes map to classes ahead of status, so callers match on a class or an
  `errorCode` rather than a message. `storage_rejected`, `checksum_mismatch` and
  `checksum_unverifiable` are `UploadError`, so a 403 from a bucket never reads as a read-only key.
- Responses are readonly camelCase objects, except `metadata` and the keys of `transforms`, which
  belong to the caller and pass through exactly as written. `toArray()` returns the API's payload.
- `FileHutch\Webhook`: `constructEvent`, `verifySignature` and `computeSignature`, byte-for-byte
  compatible with the server and the other SDKs.

### Laravel

- Auto-discovered service provider, `config/filehutch.php` from `FILE_HUTCH_API_KEY` and
  `FILE_HUTCH_URL`, and a `FileHutch` facade.
- `HasHutch`, the Eloquent counterpart of the Rails gem's `has_hutch`: one string column per
  attachment, upload on save, verification of ids from browser uploads, local policy checks as
  `ValidationException`, `*_url`, `*SignedUrl`, `*TransformUrl` and `purge*`, and deletion of
  replaced and deleted files (not soft-deleted ones).
- Direct-upload routes at `/file_hutch/uploads`, where `<filehutch-upload>` posts by default.
  Closed until `DirectUploads::authorize()` is called; on complete the policy is looked up from the
  file, never taken from the client.
