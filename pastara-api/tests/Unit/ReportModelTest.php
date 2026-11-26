<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Report;

it('verifies HMAC-SHA256 secret tokens accurately', function (): void {
    $secretReport = new Report([
        'is_secret' => true,
        'secret_token_hash' => Report::hashSecretToken('TKN-DEMO-2027'),
    ]);

    expect($secretReport->verifySecretToken('TKN-DEMO-2027'))->toBeTrue()
        ->and($secretReport->verifySecretToken(' TKN-DEMO-2027 '))->toBeTrue()
        ->and($secretReport->verifySecretToken('TKN-WRONG-9999'))->toBeFalse()
        ->and($secretReport->verifySecretToken(null))->toBeFalse()
        ->and($secretReport->verifySecretToken(''))->toBeFalse();

    $publicReport = new Report([
        'is_secret' => false,
        'secret_token_hash' => Report::hashSecretToken('TKN-DEMO-2027'),
    ]);

    expect($publicReport->verifySecretToken('TKN-DEMO-2027'))->toBeFalse();
});

it('blocks application-level update and delete on AuditLog model', function (): void {
    $log = new AuditLog;

    expect(fn (): bool => $log->update(['note' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn (): ?bool => $log->delete())->toThrow(LogicException::class);
});
