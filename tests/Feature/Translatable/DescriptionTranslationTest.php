<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomPermission;
use RoundlyConsulting\Permissions\Tests\Fixtures\ExactDescriptionRole;
use RoundlyConsulting\Translatable\Enums\FallbackMode;

beforeEach(function (): void {
    // The fallback chain's step 2 is the app's fallback locale.
    config()->set('app.fallback_locale', 'en');
});

it('writes a per-locale array and reads back the current locale', function (): void {
    App::setLocale('en');

    $permission = Permission::factory()->create([
        'description' => ['en' => 'View users', 'sk' => 'Zobraziť používateľov'],
    ]);

    expect($permission->fresh()->description)->toBe('View users');
});

it('writes a bare string into the current locale', function (): void {
    App::setLocale('sk');

    $role = Role::factory()->create();
    $role->description = 'Správca';
    $role->save();

    expect($role->fresh()->getTranslations('description'))->toBe(['sk' => 'Správca']);

    App::setLocale('sk');
    expect($role->fresh()->description)->toBe('Správca');
});

it('changes what description returns when the locale switches', function (): void {
    $role = Role::factory()->create([
        'description' => ['en' => 'Administrator', 'sk' => 'Administrátor'],
    ]);

    App::setLocale('en');
    expect($role->fresh()->description)->toBe('Administrator');

    App::setLocale('sk');
    expect($role->fresh()->description)->toBe('Administrátor');
});

it('under the default fallback, a missing locale falls back to the app fallback locale', function (): void {
    config()->set('permissions.description_fallback', FallbackMode::Fallback);

    $permission = Permission::factory()->create(['description' => ['en' => 'View users']]);

    App::setLocale('sk'); // sk missing; en (fallback locale) present

    expect($permission->fresh()->description)->toBe('View users');
});

it('under the default fallback, a locale with no fallback value resolves to null — no cross-locale disclosure', function (): void {
    config()->set('permissions.description_fallback', FallbackMode::Fallback);

    // Only sk is stored. Requesting de, with the fallback locale (en) also absent,
    // must NOT surface the sk value — that would be a cross-locale disclosure.
    $permission = Permission::factory()->create(['description' => ['sk' => 'Zobraziť']]);

    App::setLocale('de');

    expect($permission->fresh()->description)->toBeNull();
});

it('under the opt-in Any fallback, a missing locale surfaces the first available value', function (): void {
    config()->set('permissions.description_fallback', FallbackMode::Any);

    // Same data as the disclosure pin above — the ONLY difference is the mode.
    $permission = Permission::factory()->create(['description' => ['sk' => 'Zobraziť']]);

    App::setLocale('de');

    expect($permission->fresh()->description)->toBe('Zobraziť');
});

it('under the opt-in None fallback, a missing locale is null even when the fallback locale has a value', function (): void {
    config()->set('permissions.description_fallback', FallbackMode::None);

    $permission = Permission::factory()->create(['description' => ['en' => 'View users']]);

    App::setLocale('sk'); // en (fallback locale) present, but None never reaches it

    expect($permission->fresh()->description)->toBeNull();
});

it('defaults to the Fallback mode when nothing is configured', function (): void {
    // The shipped default is the non-disclosing Fallback mode.
    expect(config('permissions.description_fallback'))->toBe(FallbackMode::Fallback)
        ->and((new Permission)->translationFallbackMode())->toBe(FallbackMode::Fallback);
});

it('serializes description as the resolved locale string, not the raw map', function (): void {
    App::setLocale('sk');

    $role = Role::factory()->create([
        'description' => ['en' => 'Administrator', 'sk' => 'Administrátor'],
    ]);

    $array = $role->fresh()->toArray();

    expect($array['description'])->toBe('Administrátor')
        ->and($array['description'])->toBeString();

    // JSON serialization agrees with property access.
    $decoded = json_decode($role->fresh()->toJson(), true);

    expect($decoded['description'])->toBe('Administrátor');
});

it('translates on a host subclass — the model seam and the trait compose', function (): void {
    config()->set('permissions.models.permission', CustomPermission::class);

    $permission = CustomPermission::query()->create([
        'name' => 'posts.edit',
        'description' => ['en' => 'Edit posts', 'sk' => 'Upraviť príspevky'],
    ]);

    App::setLocale('sk');
    expect($permission->fresh()->description)->toBe('Upraviť príspevky');

    App::setLocale('en');
    expect($permission->fresh()->description)->toBe('Edit posts')
        ->and($permission->fresh())->toBeInstanceOf(CustomPermission::class);
});

it('keeps a per-model fallback mode instead of overwriting it with the config default', function (): void {
    // The documented override: a subclass declaring its own $translatableFallbackMode.
    config()->set('permissions.description_fallback', FallbackMode::Fallback);

    $role = ExactDescriptionRole::query()->create([
        'name' => 'editor',
        'description' => ['en' => 'Editor'],
    ]);

    App::setLocale('sk'); // sk missing; the config's Fallback mode would surface `en`

    expect($role->translationFallbackMode())->toBe(FallbackMode::None)
        ->and($role->fresh()?->description)->toBeNull()
        ->and((new Role)->translationFallbackMode())->toBe(FallbackMode::Fallback);
});
