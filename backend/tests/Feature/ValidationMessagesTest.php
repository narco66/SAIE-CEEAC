<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * UX-03 : les erreurs de saisie renvoyées par le serveur sont rédigées en français, avec des noms de champs lisibles,
 * et jamais sous forme de clés de traduction brutes (« validation.required »).
 */
class ValidationMessagesTest extends TestCase
{
    public function test_field_errors_are_written_in_french_with_readable_field_names(): void
    {
        $agent = $this->userWith('agent');

        $response = $this->actingAs($agent)->postJson('/api/v1/reservations', ['start_at' => 'demain', 'participant_count' => 0])->assertUnprocessable();

        $errors = collect($response->json('errors'))->flatten();
        $this->assertSame('Le champ objet est obligatoire.', $response->json('errors.subject.0'));
        $this->assertSame('Le champ début doit être une date valide.', $response->json('errors.start_at.0'));
        $this->assertTrue($errors->every(fn (string $message): bool => ! str_starts_with($message, 'validation.')), $errors->implode(' | '));
    }
}
