<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests para account_id en la tabla links (Fase 2).
 *
 * Cubre:
 *  - Links pre-feature conservan account_id = null
 *  - Nuevo link puede asociarse a una cuenta
 *  - Relación Link::account() resuelve al modelo Account correcto
 *  - Link con account_id = null → relación retorna null (sin excepción)
 *  - account() no rompe tests de redirect / isUsable existentes
 */
class LinkAccountIdTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupervisor(): User
    {
        return User::factory()->create([
            'role'  => 'supervisor',
            'email' => 'sv_link@webtilia.com',
        ]);
    }

    private function makeAccount(User $supervisor): Account
    {
        return Account::create([
            'name'          => 'Cuenta para Links',
            'supervisor_id' => $supervisor->id,
            'is_active'     => true,
        ]);
    }

    // =========================================================================
    // account_id nullable en links existentes (pre-feature)
    // =========================================================================

    public function test_link_existente_tiene_account_id_null(): void
    {
        $link = Link::factory()->create(['account_id' => null]);

        $this->assertNull($link->account_id);
    }

    public function test_link_sin_account_relacion_retorna_null(): void
    {
        $link = Link::factory()->create(['account_id' => null]);

        $this->assertNull($link->account);
    }

    // =========================================================================
    // Nuevo link con account_id
    // =========================================================================

    public function test_nuevo_link_puede_asociarse_a_una_cuenta(): void
    {
        $sv      = $this->makeSupervisor();
        $account = $this->makeAccount($sv);

        $link = Link::factory()->create(['account_id' => $account->id]);

        $this->assertEquals($account->id, $link->account_id);
    }

    public function test_relacion_account_resuelve_al_modelo_correcto(): void
    {
        $sv      = $this->makeSupervisor();
        $account = $this->makeAccount($sv);

        $link = Link::factory()->create(['account_id' => $account->id]);
        $link->refresh();

        $this->assertNotNull($link->account);
        $this->assertEquals($account->id, $link->account->id);
        $this->assertEquals('Cuenta para Links', $link->account->name);
    }

    // =========================================================================
    // account_id está en fillable
    // =========================================================================

    public function test_account_id_esta_en_fillable(): void
    {
        $sv      = $this->makeSupervisor();
        $account = $this->makeAccount($sv);

        $link = Link::create([
            'slug'            => 'test-fill-' . uniqid(),
            'destination_url' => 'https://example.com',
            'account_id'      => $account->id,
        ]);

        $this->assertEquals($account->id, $link->account_id);
    }

    // =========================================================================
    // Funcionalidades existentes no se rompen
    // =========================================================================

    public function test_link_con_account_id_sigue_siendo_usable(): void
    {
        $sv      = $this->makeSupervisor();
        $account = $this->makeAccount($sv);

        $link = Link::factory()->create([
            'account_id' => $account->id,
            'is_active'  => true,
            'expires_at' => null,
        ]);

        $this->assertTrue($link->isUsable());
    }

    public function test_link_sin_account_id_sigue_siendo_usable(): void
    {
        $link = Link::factory()->create([
            'account_id' => null,
            'is_active'  => true,
            'expires_at' => null,
        ]);

        $this->assertTrue($link->isUsable());
    }

    public function test_relacion_account_no_afecta_shortUrl(): void
    {
        $sv      = $this->makeSupervisor();
        $account = $this->makeAccount($sv);

        $link = Link::factory()->create([
            'slug'       => 'shorturl-test',
            'account_id' => $account->id,
        ]);

        // shortUrl() no debe lanzar excepción ni devolver vacío
        $shortUrl = $link->shortUrl();
        $this->assertStringContainsString('shorturl-test', $shortUrl);
    }
}
