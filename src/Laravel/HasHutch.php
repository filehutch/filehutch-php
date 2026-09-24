<?php

declare(strict_types=1);

namespace FileHutch\Laravel;

use FileHutch\Client;
use FileHutch\ContentType;
use FileHutch\Exceptions\InvalidStateError;
use FileHutch\Exceptions\NotFoundError;
use FileHutch\Resources\File;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * `has_hutch` for Eloquent. The model stores one string column per attachment,
 * `<name>_file_id`, holding a FileHutch file id. Nothing about storage leaks in.
 *
 *   class User extends Model
 *   {
 *       use HasHutch;
 *
 *       protected function hutches(): array
 *       {
 *           return [
 *               'avatar' => 'avatars',
 *               'contract' => ['policy' => 'documents', 'dependent' => false],
 *           ];
 *       }
 *   }
 *
 *   $user->avatar = $request->file('avatar');  // uploaded to storage on save
 *   $user->avatar = 'file_…';                  // id from a browser direct upload: verified on save
 *   $user->avatar;                             // FileHutch\Resources\File or null (fetched lazily, cached)
 *   $user->avatar_url;                         // public URL (public policies)
 *   $user->avatarSignedUrl(expiresIn: 600);    // any file
 *   $user->avatarTransformUrl('thumb');        // a named transform from the dashboard
 *   $user->purgeAvatar();                      // deletes remotely, clears the column
 *
 * Options: `column` (default "<name>_file_id"), `dependent` (default true:
 * delete the file when the record is deleted or the attachment is replaced),
 * `verify` (default true: an id assigned from a form must be a ready file
 * uploaded under this policy).
 *
 * The trait overrides getAttribute, setAttribute and __call on the model. A
 * second trait that overrides the same three needs `insteadof` to combine them.
 */
trait HasHutch
{
    /** @var array<string, File> */
    private array $hutchCache = [];

    /** @var array<string, \SplFileInfo> */
    private array $hutchStaged = [];

    /** @var array<string, string> */
    private array $hutchReplaced = [];

    /** @var array<class-string, array<string, array{policy: string, column: string, dependent: bool, verify: bool}>> */
    private static array $hutchDefinitions = [];

    /**
     * Attachment name => policy name, or => ['policy' => …, 'column' => …, 'dependent' => …, 'verify' => …].
     *
     * @return array<string, string|array<string, mixed>>
     */
    abstract protected function hutches(): array;

    public static function bootHasHutch(): void
    {
        static::saving(static fn (self $model) => $model->hutchBeforeSave());
        static::saved(static fn (self $model) => $model->hutchAfterSave());
        static::deleted(static fn (self $model) => $model->hutchAfterDelete());
    }

    // -- Reading ------------------------------------------------------------------

    /** The attached file, or null. Fetched once and cached for as long as the id stays the same. */
    public function hutchFile(string $name): ?File
    {
        $id = $this->hutchId($name);
        if ($id === null) {
            return null;
        }
        if (isset($this->hutchCache[$name]) && $this->hutchCache[$name]->id === $id) {
            return $this->hutchCache[$name];
        }

        try {
            return $this->hutchCache[$name] = self::hutchClient()->file($id);
        } catch (NotFoundError) {
            return null;
        }
    }

    /** Whether an id is stored. Costs no request, unlike reading the attribute. */
    public function hutchAttached(string $name): bool
    {
        return $this->hutchId($name) !== null;
    }

    /** The stable public URL. Null for private files, which need hutchSignedUrl. */
    public function hutchUrl(string $name): ?string
    {
        return $this->hutchFile($name)?->url;
    }

    /** A short-lived URL that works for private and public files alike. */
    public function hutchSignedUrl(string $name, ?int $expiresIn = null, ?string $disposition = null): ?string
    {
        $id = $this->hutchId($name);
        if ($id === null) {
            return null;
        }

        try {
            return self::hutchClient()->signedUrl($id, $expiresIn, $disposition)->url;
        } catch (NotFoundError) {
            return null;
        }
    }

    /** A named transform. Free for public images, whose URLs are on the payload; one request for private ones. */
    public function hutchTransformUrl(string $name, string $transform, ?int $expiresIn = null): ?string
    {
        $file = $this->hutchFile($name);
        if ($file === null) {
            return null;
        }

        return $file->transforms[$transform] ?? self::hutchClient()->transformUrl($file->id, $transform, $expiresIn)->url;
    }

    /** Deletes the file remotely and clears the column, without firing model events. */
    public function purgeHutch(string $name): bool
    {
        $column = $this->hutchDefinition($name)['column'];
        $id = $this->hutchId($name);
        if ($id !== null) {
            self::hutchDeleteQuietly($id);
        }
        unset($this->hutchCache[$name], $this->hutchStaged[$name], $this->hutchReplaced[$name]);

        if ($this->exists) {
            $this->newQueryWithoutScopes()->whereKey($this->getKey())->update([$column => null]);
        }
        $this->attributes[$column] = null;
        $this->syncOriginalAttribute($column);

        return true;
    }

    // -- Eloquent plumbing --------------------------------------------------------

    public function getAttribute($key)
    {
        if (is_string($key)) {
            if ($this->hutchDefinitionOrNull($key) !== null) {
                return $this->hutchFile($key);
            }
            if (str_ends_with($key, '_url') && $this->hutchDefinitionOrNull($name = substr($key, 0, -4)) !== null) {
                return $this->hutchUrl($name);
            }
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if (is_string($key) && $this->hutchDefinitionOrNull($key) !== null) {
            $this->hutchAssign($key, $value);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function __call($method, $parameters)
    {
        foreach (array_keys($this->hutchDefinitions()) as $name) {
            $camel = Str::camel($name);
            if ($method === "{$camel}SignedUrl") {
                return $this->hutchSignedUrl($name, ...$parameters);
            }
            if ($method === "{$camel}TransformUrl") {
                return $this->hutchTransformUrl($name, ...$parameters);
            }
            if ($method === 'purge' . Str::studly($name)) {
                return $this->purgeHutch($name);
            }
        }

        return parent::__call($method, $parameters);
    }

    // -- Assignment and lifecycle ------------------------------------------------

    private function hutchAssign(string $name, mixed $value): void
    {
        $column = $this->hutchDefinition($name)['column'];
        unset($this->hutchStaged[$name], $this->hutchCache[$name]);

        if ($value === null || $value === '') {
            parent::setAttribute($column, null);
        } elseif ($value instanceof File) {
            $this->hutchCache[$name] = $value;
            parent::setAttribute($column, $value->id);
        } elseif (is_string($value)) {
            if (!Client::isId($value)) {
                throw new \InvalidArgumentException(json_encode($value) . ' is not a FileHutch file id');
            }
            parent::setAttribute($column, $value);
        } elseif ($value instanceof \SplFileInfo) {
            // Uploaded on save, so an abandoned form leaves nothing in storage.
            $this->hutchStaged[$name] = $value;
            parent::setAttribute($column, null);
        } else {
            throw new \InvalidArgumentException('Cannot attach ' . get_debug_type($value) . " to {$name}: pass an UploadedFile, an SplFileInfo, a File, or a file id.");
        }
    }

    private function hutchBeforeSave(): void
    {
        // Everything is checked before anything is uploaded, so one bad field
        // cannot leave another field's bytes orphaned in storage.
        $messages = [];
        foreach ($this->hutchDefinitions() as $name => $definition) {
            if ($problem = $this->hutchProblem($name, $definition)) {
                $messages[$name] = [$problem];
            }
        }
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }

        foreach ($this->hutchDefinitions() as $name => $definition) {
            if (isset($this->hutchStaged[$name])) {
                $source = $this->hutchStaged[$name];
                $file = self::hutchClient()->upload($source, policy: $definition['policy'], contentType: self::hutchContentType($source));
                unset($this->hutchStaged[$name]);
                $this->hutchCache[$name] = $file;
                parent::setAttribute($definition['column'], $file->id);
            }

            $column = $definition['column'];
            $previous = $this->getOriginal($column);
            if ($definition['dependent'] && is_string($previous) && $previous !== '' && $this->isDirty($column)) {
                $this->hutchReplaced[$name] = $previous;
            }
        }
    }

    /** @param array{policy: string, column: string, dependent: bool, verify: bool} $definition */
    private function hutchProblem(string $name, array $definition): ?string
    {
        if (isset($this->hutchStaged[$name])) {
            // Checked locally so a wrong type or an oversized file costs no round
            // trip. A policy that cannot be loaded skips this; the server still enforces it.
            $source = $this->hutchStaged[$name];
            $policy = Policies::find(self::hutchClient(), $definition['policy']);
            if ($policy === null) {
                return null;
            }
            $type = self::hutchContentType($source);
            if (!$policy->allowsContentType($type)) {
                return "The {$name} type {$type} is not allowed.";
            }
            $size = $source->getSize();
            if (is_int($size) && !$policy->allowsByteSize($size)) {
                return "The {$name} is larger than {$policy->maximumSize} bytes.";
            }

            return null;
        }

        $id = $this->hutchId($name);
        if (!$definition['verify'] || $id === null || !$this->isDirty($definition['column'])
            || (isset($this->hutchCache[$name]) && $this->hutchCache[$name]->id === $id)) {
            return null;
        }

        try {
            $file = self::hutchClient()->file($id);
        } catch (NotFoundError) {
            return "The {$name} does not exist.";
        }
        if (!$file->isReady()) {
            return "The {$name} upload is {$file->status}.";
        }
        if ($file->policy !== $definition['policy']) {
            return "The {$name} was uploaded under the {$file->policy} policy.";
        }
        $this->hutchCache[$name] = $file;

        return null;
    }

    private function hutchAfterSave(): void
    {
        foreach ($this->hutchReplaced as $name => $id) {
            if ($id !== $this->hutchId($name)) {
                self::hutchDeleteQuietly($id);
            }
        }
        $this->hutchReplaced = [];
    }

    private function hutchAfterDelete(): void
    {
        // A soft delete keeps the row, so it keeps the file too. forceDelete()
        // fires `deleted` as well, with isForceDeleting() true.
        if (method_exists($this, 'isForceDeleting') && !$this->isForceDeleting()) {
            return;
        }
        foreach ($this->hutchDefinitions() as $name => $definition) {
            if ($definition['dependent'] && ($id = $this->hutchId($name)) !== null) {
                self::hutchDeleteQuietly($id);
            }
        }
    }

    // -- Helpers --------------------------------------------------------------------

    private function hutchId(string $name): ?string
    {
        $id = parent::getAttribute($this->hutchDefinition($name)['column']);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @return array{policy: string, column: string, dependent: bool, verify: bool} */
    private function hutchDefinition(string $name): array
    {
        return $this->hutchDefinitionOrNull($name)
            ?? throw new \InvalidArgumentException(static::class . " has no hutch named {$name}");
    }

    /** @return array{policy: string, column: string, dependent: bool, verify: bool}|null */
    private function hutchDefinitionOrNull(string $name): ?array
    {
        return $this->hutchDefinitions()[$name] ?? null;
    }

    /** @return array<string, array{policy: string, column: string, dependent: bool, verify: bool}> */
    private function hutchDefinitions(): array
    {
        return self::$hutchDefinitions[static::class] ??= (function (): array {
            $definitions = [];
            foreach ($this->hutches() as $name => $options) {
                $options = is_string($options) ? ['policy' => $options] : $options;
                if (!isset($options['policy'])) {
                    throw new \InvalidArgumentException(static::class . " hutch {$name} needs a policy");
                }
                $definitions[$name] = [
                    'policy' => (string) $options['policy'],
                    'column' => (string) ($options['column'] ?? "{$name}_file_id"),
                    'dependent' => (bool) ($options['dependent'] ?? true),
                    'verify' => (bool) ($options['verify'] ?? true),
                ];
            }

            return $definitions;
        })();
    }

    private static function hutchContentType(\SplFileInfo $source): string
    {
        $type = method_exists($source, 'getMimeType') ? $source->getMimeType() : null;
        if (is_string($type) && $type !== '') {
            return $type;
        }
        $name = method_exists($source, 'getClientOriginalName') ? $source->getClientOriginalName() : $source->getFilename();

        return ContentType::guess((string) $name);
    }

    private static function hutchDeleteQuietly(string $id): void
    {
        try {
            self::hutchClient()->deleteFile($id);
        } catch (NotFoundError|InvalidStateError) {
            // Already gone is the outcome we wanted.
        }
    }

    private static function hutchClient(): Client
    {
        return Container::getInstance()->make(Client::class);
    }
}
