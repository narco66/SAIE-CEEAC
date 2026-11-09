<?php

namespace App\Models;

use App\Models\Concerns\GuardsVersion;
use App\Services\RealtimeBus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Screen extends Model
{
    use GuardsVersion, HasUuids;

    protected static function booted(): void
    {
        static::updated(fn (Screen $screen) => RealtimeBus::publish('screen', $screen->id));
    }

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Version initiale (API-04), présente dès la réponse de création.
     *
     * @var array<string, int>
     */
    protected $attributes = ['version' => 1];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operating_window' => 'array',
            'audio_enabled' => 'boolean',
            'touch_enabled' => 'boolean',
            'panel_state_at' => 'datetime',
            'is_demo' => 'boolean',
            'installed_on' => 'date',
            'warranty_until' => 'date',
            'archived_at' => 'datetime',
            'acquired_on' => 'date',
            'interfaces' => 'array',
            'commissioned_at' => 'datetime',
            'commissioning_report' => 'array',
        ];
    }

    /** §5.2-g : interfaces physiques décrites sur la fiche. */
    public const INTERFACES = ['hdmi', 'displayport', 'dvi', 'vga', 'usb', 'rj45', 'wifi', 'rs232', 'hdbaset', 'audio', 'sdi'];

    /**
     * §7.1-i : fuseau horaire du site de l'écran (référentiel), sinon celui de la plateforme.
     */
    public function effectiveTimezone(): string
    {
        $this->loadMissing('location.floor.building.site');
        $tz = $this->location?->floor?->building?->site?->timezone;

        return is_string($tz) && $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : (string) config('saie.timezone');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * §5.1-e : catégorie paramétrable.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ScreenCategory::class);
    }

    /**
     * §15.1 : auteur de la mise en service.
     */
    public function commissioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commissioned_by');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ScreenGroup::class, 'group_members', 'screen_id', 'group_id');
    }

    /**
     * PLY-08 : plage en vigueur — celle de l'écran (dérogation), sinon celle de son premier groupe qui en porte une
     * (ordre alphabétique de code, déterministe), sinon le paramètre général.
     *
     * @return array<string, mixed>
     */
    public function effectiveOperatingWindow(): array
    {
        if ($this->operating_window) {
            return $this->operating_window;
        }
        $group = $this->relationLoaded('groups')
            ? $this->groups->filter(fn (ScreenGroup $g) => $g->operating_window)->sortBy('code')->first()
            : $this->groups()->whereNotNull('operating_window')->orderBy('code')->first();

        return $group?->operating_window ?: (array) Setting::effective('operating.default_window');
    }

    /**
     * @return BelongsTo<ScreenProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ScreenProfile::class);
    }

    /**
     * §7.3 : profil en vigueur — celui de l'écran, sinon celui de son premier groupe qui en porte un (ordre de code).
     */
    public function effectiveProfile(): ?ScreenProfile
    {
        if ($this->profile_id) {
            return $this->profile?->is_active ? $this->profile : null;
        }
        $groupProfileId = $this->groups()->whereNotNull('profile_id')->orderBy('code')->value('profile_id');

        return $groupProfileId ? ScreenProfile::query()->where('is_active', true)->find($groupProfileId) : null;
    }

    public function activeDevice(): HasOne
    {
        return $this->hasOne(Device::class)->where('status', 'active');
    }
}
