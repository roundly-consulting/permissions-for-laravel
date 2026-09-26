<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Sluggable\SluggableServiceProvider;

/**
 * sluggable reaches permissions only through translatable, and pre-Packagist Composer does not
 * inherit a dependency's `repositories`. If the sluggable path + VCS entries ever go missing from
 * composer.json, this fails with a readable message instead of an autoload error deep inside
 * translatable.
 */
it('installs sluggable transitively through translatable', function (): void {
    expect(class_exists(SluggableServiceProvider::class))
        ->toBeTrue('sluggable-for-laravel is not installed — check the sluggable repositories entries in composer.json');
});

/**
 * HasTranslations now also carries sluggable's ProvidesLocaleMaps methods. Role and Permission
 * use the trait without `implements Translatable` and without HasSlug — pin that the new
 * methods arrive intact and resolve against the translatable `description` attribute.
 */
it('exposes the locale-map seam on role and permission descriptions', function (): void {
    foreach ([new Role, new Permission] as $record) {
        expect($record->isLocaleMapAttribute('description'))->toBeTrue()
            ->and($record->isLocaleMapAttribute('name'))->toBeFalse()
            ->and($record->setLocaleMap('description', ['en' => 'Edit', 'sk' => 'Upraviť'])->getLocaleMap('description'))
            ->toBe(['en' => 'Edit', 'sk' => 'Upraviť']);
    }
});
