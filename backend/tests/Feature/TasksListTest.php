<?php

namespace Tests\Feature;

use App\Models\Content;
use Tests\TestCase;

/**
 * Non-régression : la liste des tâches d'un auteur dont une version a été renvoyée en correction se charge (la fusion des
 * validations, des retours et des prestations échouait sur une collection de modèles).
 */
class TasksListTest extends TestCase
{
    public function test_an_author_with_a_returned_version_sees_the_correction_task(): void
    {
        $author = $this->userWith('contributeur');
        $content = Content::query()->create(['reference' => 'CNT-RET', 'title' => 'Consignes', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'interne', 'expires_at' => now()->addDays(10), 'status' => 'draft']);
        $content->languages()->create(['lang' => 'fr', 'title' => 'Consignes', 'status' => 'returned', 'last_return_reason' => 'Préciser le point de rassemblement']);

        $this->actingAs($author)->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('data.0.type', 'content_return')->assertJsonPath('data.0.reason', 'Préciser le point de rassemblement');
    }
}
