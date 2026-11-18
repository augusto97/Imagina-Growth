<?php

use App\Support\Console\WorkspaceOption;

// --workspace en los comandos igr:* (un valor desconocido nunca se ignora en silencio).
it('acepta el ID público o el interno y falla con uno que no existe', function () {
    $owner = $this->userWith2fa();
    $a = $this->workspaceFor($owner);
    $b = $this->workspaceFor($owner);

    expect(WorkspaceOption::resolve($a->publicId())->modelKeys())->toBe([$a->id])
        ->and(WorkspaceOption::resolve([$a->id, $b->publicId()])->modelKeys())->toEqualCanonicalizing([$a->id, $b->id])
        ->and(WorkspaceOption::resolve(null)->count())->toBeGreaterThanOrEqual(2)
        ->and(WorkspaceOption::resolve([], allWhenEmpty: false))->toHaveCount(0);
    expect(fn () => WorkspaceOption::resolve('ws_01ZZZZZZZZZZZZZZZZZZZZZZZZ'))->toThrow(InvalidArgumentException::class, 'No existe el espacio de trabajo');
    expect(fn () => WorkspaceOption::resolve('pinturas'))->toThrow(InvalidArgumentException::class);

    // En la terminal Artisan muestra el mensaje y sale con código 1.
    expect(fn () => $this->artisan('igr:rfm', ['--workspace' => 'ws_01ZZZZZZZZZZZZZZZZZZZZZZZZ'])->run())->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->artisan('igr:segments:install-library', ['--workspace' => ['nada']])->run())->toThrow(InvalidArgumentException::class);
    $this->artisan('igr:rfm', ['--workspace' => $a->id])->assertSuccessful();
});
