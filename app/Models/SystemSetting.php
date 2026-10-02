<?php

namespace App\Models;

use App\Services\QueryCacheService;
use Database\Factories\SystemSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'type'])]
class SystemSetting extends Model
{
    /** @use HasFactory<SystemSettingFactory> */
    use HasFactory;

    private const CACHE_TTL_SECONDS = 300;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return app(QueryCacheService::class)->rememberGroup(
            'system_settings',
            $key,
            static fn (): mixed => static::query()->where('key', $key)->first()?->value,
            self::CACHE_TTL_SECONDS,
        ) ?? $default;
    }

    public static function setValue(string $key, mixed $value, string $type = 'string'): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type],
        );
    }
}
