<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetVersion extends Model
{
    use HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['text_content'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtitles' => 'array',
            'derivatives' => 'array',
            'processed_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * DOC-01 : fichier réellement diffusé aux lecteurs — la dérivée H.264/AAC d'une vidéo transcodée, sinon l'original.
     *
     * @return array{storage_key: string, sha256: string, size: int, mime: string}
     */
    public function broadcastFile(): array
    {
        $derived = $this->derivatives['video']['transcoded'] ?? null;
        if (is_array($derived) && ! empty($derived['storage_key']) && ! empty($derived['sha256'])) {
            return ['storage_key' => $derived['storage_key'], 'sha256' => $derived['sha256'], 'size' => (int) $derived['size'], 'mime' => $derived['mime'] ?? 'video/mp4'];
        }

        // PUB-09 : un fichier bureautique converti est diffusé dans sa copie PDF empreintée.
        $pdf = $this->derivatives['broadcast_pdf'] ?? null;
        if (is_array($pdf) && ! empty($pdf['storage_key']) && ! empty($pdf['sha256'])) {
            return ['storage_key' => $pdf['storage_key'], 'sha256' => $pdf['sha256'], 'size' => (int) $pdf['size'], 'mime' => 'application/pdf'];
        }

        return ['storage_key' => $this->storage_key, 'sha256' => (string) $this->sha256, 'size' => (int) $this->size, 'mime' => (string) $this->mime];
    }

    /**
     * Nature du fichier effectivement diffusé : un bureautique converti est rendu comme un PDF.
     */
    public function broadcastKind(): string
    {
        $kind = $this->asset?->kind ?? 'image';

        return $kind === 'office' && ! empty($this->derivatives['broadcast_pdf']['sha256']) ? 'pdf' : $kind;
    }
}
