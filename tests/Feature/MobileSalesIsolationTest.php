<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use App\Http\Middleware\MobileSalesSession;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

function mobileSalesRequest(string $name, array $payload = []): Request
{
    $request = Request::create('/test', $payload ? 'POST' : 'GET', $payload);
    $request->setLaravelSession(session()->driver());
    $request->setRouteResolver(fn () => (new Route(['GET', 'POST'], '/test', fn () => null))->name($name));
    $user                   = Mockery::mock(User::class)->makePartial();
    $user->id               = 7;
    $user->current_store_id = 3;
    $stores                 = Mockery::mock(BelongsToMany::class);
    $stores->shouldReceive('whereKey')->with(3)->andReturnSelf();
    $stores->shouldReceive('exists')->andReturnTrue();
    $user->shouldReceive('stores')->andReturn($stores);
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('keeps mobile cart writes out of the desktop cart session', function (): void {
    session()->put('pos_carts', [1 => ['desktop']]);
    session()->put('mobile_sales.7.3.pos_carts', [1 => ['mobile']]);
    $response = (new MobileSalesSession)->handle(mobileSalesRequest('mobile.sales-workspace'), function () {
        expect(session('pos_carts'))->toBe([1 => ['mobile']]);
        session()->put('pos_carts', [1 => ['mobile', 'added']]);
        session()->put('pos_cart_clients', [1 => 9]);

        return response('ok');
    });

    expect(session('pos_carts'))->toBe([1 => ['desktop']])
        ->and(session('mobile_sales.7.3.pos_carts'))->toBe([1 => ['mobile', 'added']])
        ->and(session()->exists('pos_cart_clients'))->toBeFalse()
        ->and(session('mobile_sales.7.3.pos_cart_clients'))->toBe([1 => 9])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('isolates the entire mobile livewire update not just mounting', function (): void {
    session()->put('pos_carts', [1 => ['desktop']]);
    $request = mobileSalesRequest('livewire.update', ['components' => [
        ['snapshot' => json_encode(['memo' => ['name' => 'mobile.sales']])],
    ]]);
    $request->headers->set('X-Livewire', '');
    (new MobileSalesSession)->handle($request, function () {
        expect(session('pos_carts'))->toBeNull();
        session()->put('pos_carts', [1 => ['mobile']]);

        return response('ok');
    });

    expect(session('pos_carts'))->toBe([1 => ['desktop']])
        ->and(session('mobile_sales.7.3.pos_carts'))->toBe([1 => ['mobile']]);
});

it('restores desktop state even when a mobile operation fails', function (): void {
    session()->put('pos_carts', [1 => ['desktop']]);

    try {
        (new MobileSalesSession)->handle(mobileSalesRequest('mobile.sales-workspace'), function () {
            session()->put('pos_carts', [1 => ['mobile']]);

            throw new RuntimeException('Offline');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Offline');
    }

    expect(session('pos_carts'))->toBe([1 => ['desktop']]);
});

it('does not intercept desktop livewire requests', function (): void {
    session()->put('pos_carts', [1 => ['desktop']]);
    $request = mobileSalesRequest('livewire.update', ['components' => [
        ['snapshot' => json_encode(['memo' => ['name' => 'app.filament.pages.pos']])],
    ]]);
    $request->headers->set('X-Livewire', '');
    (new MobileSalesSession)->handle($request, function () {
        expect(session('pos_carts'))->toBe([1 => ['desktop']]);

        return response('ok');
    });
    expect(session()->exists('mobile_sales'))->toBeFalse();
});

it('keeps mobile views and bundles outside the filament sales template', function (): void {
    $desktop = file_get_contents(resource_path('views/filament/pages/pos.blade.php'));
    $global  = file_get_contents(resource_path('js/app.js'));

    expect($desktop)->not->toContain('mobile.partials.sales', 'pos-mobile', 'mobile/sales')
        ->and($global)->not->toContain('pos-mobile', 'mobile/sales');
});
