<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Sondes d'audit (hors dépôt) : C-01 second facteur non imposé côté serveur ; A-07 média restreint visible sans périmètre. */
class AuditProbeSecurityTest extends TestCase
{
    public function test_c01_admin_without_mfa_reaches_admin_api(): void
    {
        config(['saie.security.mfa_enforced' => true]);
        $admin = $this->userWith('admin_tech');
        $login = $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'])->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'Secret-Passw0rd!']);
        fwrite(STDERR, 'login='.$login->status().' '.json_encode($login->json())."\n");
        $users = $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'])->getJson('/api/v1/users');
        fwrite(STDERR, 'GET /users sans second facteur='.$users->status()."\n");
        $this->assertTrue(true);
    }

    public function test_a07_restricted_asset_downloadable_by_unscoped_contributor(): void
    {
        $owner = $this->userWith('contributeur', 'DSI', null, 'DSI');
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $r = $this->actingAs($owner)->post('/api/v1/assets', ['file' => UploadedFile::fake()->createWithContent('secret.pdf', $pdf), 'classification' => 'restreint'])->assertCreated();
        $versionId = $r->json('version.id');
        \App\Models\AssetVersion::query()->whereKey($versionId)->update(['scan_status' => 'ready']);
        $other = $this->userWith('contributeur', 'COM');
        $this->app['auth']->forgetGuards();
        $dl = $this->actingAs($other)->get("/api/v1/asset-versions/$versionId/file");
        fwrite(STDERR, "contributeur d'une autre structure, sans périmètre : GET fichier restreint=".$dl->getStatusCode()."\n");
        $scoped = $this->userWith('contributeur', 'COM', null, 'COM');
        $this->app['auth']->forgetGuards();
        fwrite(STDERR, 'contributeur avec périmètre COM : GET fichier restreint='.$this->actingAs($scoped)->get("/api/v1/asset-versions/$versionId/file")->getStatusCode()."\n");
        $this->assertTrue(true);
    }
}
