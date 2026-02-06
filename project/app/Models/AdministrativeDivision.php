<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdministrativeDivision extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_code','type','name','slug','code','parent_id',
        'centroid_lat','centroid_lng','population','meta','is_active'
    ];

    protected $casts = [
        'meta' => 'array',
        'is_active' => 'boolean',
    ];

    // Normalize your catalogue of types in one place
    public const TYPES = [
        'country','state','province','region','county','subcounty',
        'constituency','district','city','borough','ward',
        'location','sublocation','village','custom',
    ];

    /* ---------------- Relations ---------------- */

    public function parent()  { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(){ return $this->hasMany(self::class, 'parent_id');  }

    /* ---------------- Accessors ---------------- */

    public function getFullNameAttribute(): string
    {
        // "Kenya > Nairobi > Westlands"
        $parts = collect(explode('/', $this->path))
            ->filter()->map(fn($slug) => Str::of($slug)->headline());
        return $parts->implode(' > ');
    }

    /* ---------------- Scopes ---------------- */

    public function scopeOfType($q, string $type)     { return $q->where('type', $type); }
    public function scopeInCountry($q, ?string $cc)   { return $q->when($cc, fn($qq)=>$qq->where('country_code',$cc)); }
    public function scopeWithin($q, self $node)       { return $q->where('path','like',$node->path.'/%'); }

    /* ---------------- Lifecycle ---------------- */

    protected static function booted(): void
    {
        // Build slug, depth, and path on create
        static::creating(function (self $m) {
            $m->slug  = $m->slug ?: Str::slug($m->name);
            $parent   = $m->parent()->first();
            $m->depth = $parent ? $parent->depth + 1 : 0;
            $prefix   = $parent ? $parent->path : trim(($m->country_code ? $m->country_code : ''), '/');
            $m->path  = trim($prefix ? "$prefix/{$m->slug}" : $m->slug, '/');
        });

        // If slug or parent changes, recompute path and shift subtree
        static::updating(function (self $m) {
            $needsRepath = $m->isDirty('slug') || $m->isDirty('parent_id') || $m->isDirty('country_code');

            if ($needsRepath) {
                $oldPath  = $m->getOriginal('path');
                $oldDepth = (int) $m->getOriginal('depth');

                $m->slug  = $m->slug ?: Str::slug($m->name);
                $parent   = $m->parent()->first();
                $m->depth = $parent ? $parent->depth + 1 : 0;
                $prefix   = $parent ? $parent->path : trim(($m->country_code ? $m->country_code : ''), '/');
                $m->path  = trim($prefix ? "$prefix/{$m->slug}" : $m->slug, '/');

                // After saving, bulk-update descendants’ paths/depths using a single SQL
                $m->saving(function () use ($m, $oldPath, $oldDepth) {
                    $m->afterSave(function () use ($m, $oldPath, $oldDepth) {
                        $newPath = $m->path;
                        $delta   = $m->depth - $oldDepth;

                        // Shift subtree: replace old prefix with new prefix, adjust depth
                        DB::statement("
                            UPDATE administrative_divisions
                            SET path = CONCAT(?, SUBSTRING(path, ?)),
                                depth = depth + ?
                            WHERE path LIKE CONCAT(?, '/%')
                        ", [
                            $newPath,
                            strlen($oldPath) + 1, // +1 to keep the slash
                            $delta,
                            $oldPath,
                        ]);
                    });
                });
            }
        });
    }

    /* ---------------- Helpers ---------------- */

    public static function findByPath(string $path): ?self
    {
        return static::where('path', trim($path, '/'))->first();
    }

    /** All descendants (as a query you can paginate/filter) */
    public function descendantsQuery()
    {
        return static::where('path','like',$this->path.'/%');
    }

    /** Direct + deep ancestors (MySQL 8 recursive CTE) */
    public function ancestors()
    {
        $sql = <<<SQL
            WITH RECURSIVE chain AS (
                SELECT id, parent_id, name, slug, type, depth, path FROM administrative_divisions WHERE id = ?
                UNION ALL
                SELECT p.id, p.parent_id, p.name, p.slug, p.type, p.depth, p.path
                FROM administrative_divisions p
                JOIN chain c ON p.id = c.parent_id
            )
            SELECT * FROM chain WHERE id <> ? ORDER BY depth
        SQL;

        return static::fromQuery($sql, [$this->id, $this->id]);
    }
}
