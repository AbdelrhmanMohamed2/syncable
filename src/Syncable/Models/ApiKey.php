<?php

namespace Syncable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'syncable_api_keys';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'key',
        'description',
        'is_active',
        'last_used_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    /**
     * Generate a new API key.
     *
     * @return string
     */
    public static function generateKey(): string
    {
        return base64_encode(Str::random(40));
    }

    /**
     * Create a new API key.
     *
     * @param string $name
     * @param string|null $description
     * @return self
     */
    public static function createKey(string $name, ?string $description = null): self
    {
        return static::create([
            'name' => $name,
            'key' => static::generateKey(),
            'description' => $description,
            'is_active' => true,
        ]);
    }

    /**
     * Mark this API key as used.
     *
     * @return self
     */
    public function markAsUsed(): self
    {
        $this->update(['last_used_at' => now()]);
        
        return $this;
    }

    /**
     * Scope a query to only include active API keys.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Disable this API key.
     *
     * @return self
     */
    public function disable(): self
    {
        $this->update(['is_active' => false]);
        
        return $this;
    }

    /**
     * Enable this API key.
     *
     * @return self
     */
    public function enable(): self
    {
        $this->update(['is_active' => true]);
        
        return $this;
    }
} 