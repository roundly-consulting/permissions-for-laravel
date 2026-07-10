<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Permissions\Enums\ModelKeyType;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

it('resolves the key type from config with a bigint fallback', function (): void {
    expect(ModelKeyType::fromConfig('bigint'))->toBe(ModelKeyType::BigInt)
        ->and(ModelKeyType::fromConfig('uuid'))->toBe(ModelKeyType::Uuid)
        ->and(ModelKeyType::fromConfig('ulid'))->toBe(ModelKeyType::Ulid)
        ->and(ModelKeyType::fromConfig('nonsense'))->toBe(ModelKeyType::BigInt)
        ->and(ModelKeyType::fromConfig(123))->toBe(ModelKeyType::BigInt);
});

it('defaults the registrar model key type to bigint', function (): void {
    expect(PermissionRegistrar::modelKeyType())->toBe(ModelKeyType::BigInt);

    config()->set('permissions.model_key_type', 'ulid');

    expect(PermissionRegistrar::modelKeyType())->toBe(ModelKeyType::Ulid);
});

it('defines the model_id column of the matching type', function (): void {
    $expected = [
        'bigint' => 'integer',
        'uuid' => 'varchar',
        'ulid' => 'varchar',
    ];

    foreach ([ModelKeyType::BigInt, ModelKeyType::Uuid, ModelKeyType::Ulid] as $type) {
        Schema::dropIfExists('key_type_probe');
        Schema::create('key_type_probe', function (Blueprint $table) use ($type): void {
            $table->id();
            $type->defineModelId($table);
        });

        expect(Schema::getColumnType('key_type_probe', 'model_id'))->toBe($expected[$type->value]);
    }

    Schema::dropIfExists('key_type_probe');
});
