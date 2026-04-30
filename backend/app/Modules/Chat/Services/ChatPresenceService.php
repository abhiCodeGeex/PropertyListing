<?php

namespace App\Modules\Chat\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatPresenceService
{
    /**
     * Avoid logging the same cache-store failure on every request.
     *
     * @var array<string, bool>
     */
    private static array $warnedStores = [];

    public function markOnline(int $userId): void
    {
        $ttl = max(30, (int) config('chat.presence.ttl_seconds', 120));

        $this->withStore(function (CacheRepository $store) use ($userId, $ttl) {
            $store->put($this->key($userId), now()->toIso8601String(), now()->addSeconds($ttl));
        });
    }

    public function markOffline(int $userId): void
    {
        $this->withStore(function (CacheRepository $store) use ($userId) {
            $store->forget($this->key($userId));
        });
    }

    public function isOnline(int $userId): bool
    {
        return (bool) $this->withStore(
            callback: fn (CacheRepository $store) => $store->has($this->key($userId)),
            default: false
        );
    }

    /**
     * @template T
     *
     * @param  callable(CacheRepository): T  $callback
     * @param  T  $default
     * @return T
     */
    private function withStore(callable $callback, mixed $default = null): mixed
    {
        foreach ($this->candidateStores() as $storeName) {
            try {
                return $callback(Cache::store($storeName));
            } catch (Throwable $exception) {
                $this->warnStoreFailure($storeName, $exception);
            }
        }

        return $default;
    }

    /**
     * @return array<int, string>
     */
    private function candidateStores(): array
    {
        return collect([
            (string) config('chat.presence.store', config('cache.default', 'file')),
            (string) config('cache.default', 'file'),
            'array',
        ])
            ->filter(fn ($store) => $store !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function warnStoreFailure(string $storeName, Throwable $exception): void
    {
        $warningKey = $storeName.'|'.$exception::class;

        if (isset(self::$warnedStores[$warningKey])) {
            return;
        }

        self::$warnedStores[$warningKey] = true;

        Log::warning('Chat presence store unavailable, falling back to the next cache store.', [
            'store' => $storeName,
            'error' => $exception->getMessage(),
        ]);
    }

    private function key(int $userId): string
    {
        return "chat:presence:user:{$userId}";
    }
}
