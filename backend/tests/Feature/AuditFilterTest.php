<?php

namespace Tests\Feature;

use App\Services\Audit;
use App\Services\ExportService;
use Tests\TestCase;

/**
 * V38 et PDF09 : l'extrait reprend tous les filtres du journal, et le type d'objet ne dépend pas de la casse.
 */
class AuditFilterTest extends TestCase
{
    public function test_object_type_filter_ignores_case_and_wildcards_are_literal(): void
    {
        $auditor = $this->userWith('auditeur', 'AUD');
        app(Audit::class)->log('content.updated', 'Content', actor: $auditor, objectLabel: 'Communiqué');
        app(Audit::class)->log('room.updated', 'Room', actor: $auditor, objectLabel: 'Salle Kivu');

        $labels = collect($this->actingAs($auditor)->getJson('/api/v1/audit-logs?object_type=content')->assertOk()->json('data'))->pluck('object_label');
        $this->assertSame(['Communiqué'], $labels->unique()->values()->all());
        $this->assertCount(0, $this->actingAs($auditor)->getJson('/api/v1/audit-logs?object_type=%25')->json('data'), '« % » est un caractère, pas un joker');
    }

    public function test_extract_applies_every_journal_filter(): void
    {
        $auditor = $this->userWith('auditeur', 'AUD');
        app(Audit::class)->log('content.updated', 'Content', actor: $auditor, objectLabel: 'Communiqué');
        app(Audit::class)->log('content.updated', 'Content', outcome: 'denied', actor: $auditor, objectLabel: 'Refusé');
        app(Audit::class)->log('room.updated', 'Room', actor: $auditor, objectLabel: 'Salle Kivu');

        $model = app(ExportService::class)->build('audit_extract', ['from' => now()->subDay()->toIso8601String(), 'to' => now()->addMinute()->toIso8601String(), 'object_type' => 'CONTENT', 'outcome' => 'success'], $auditor);
        $objects = collect($model['sections'][0]['table']['rows'])->pluck(5);

        $this->assertSame(['Content Communiqué'], $objects->values()->all());
    }
}
