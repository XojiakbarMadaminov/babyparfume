<?php

namespace App\Livewire\Mobile;

use App\Models\Product;
use App\Models\Category;
use App\Filament\Pages\Pos;
use Livewire\WithPagination;
use App\Services\CartService;
use Livewire\Attributes\Locked;
use Illuminate\Contracts\View\View;
use Filament\Notifications\Notification;

class Sales extends Pos
{
    use WithPagination;

    #[Locked]
    public ?int $mobileStoreId = null;

    public ?int $mobileCategory = null;

    public array $cartReceiptDiscounts = [];

    public function boot(): void
    {
        $user = auth()->user();
        abort_unless($user && Pos::canAccess(), 403);
        abort_unless($user->current_store_id && $user->stores()->whereKey($user->current_store_id)->exists(), 403);
    }

    public function mount(): void
    {
        $this->mobileStoreId = auth()->user()->current_store_id;
        parent::mount();
    }

    public function hydrate(): void
    {
        abort_unless($this->mobileStoreId === auth()->user()->current_store_id, 409, 'Do‘kon o‘zgargan. Sotuv sahifasini qayta oching.');
    }

    public function dehydrate(): void
    {
        if ($this->canSeeCostPrice()) {
            return;
        }

        foreach ($this->cart as &$item) {
            unset($item['initial_price'], $item['yuan_price']);
        }
        unset($item);

        if (isset($this->receiptData['items'])) {
            foreach ($this->receiptData['items'] as &$item) {
                unset($item['initial_price'], $item['yuan_price']);
            }
            unset($item);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedMobileCategory(): void
    {
        $this->resetPage();
    }

    public function clearCurrentCart(): void
    {
        app(CartService::class)->clear($this->activeCartId);
        unset($this->cartReceiptDiscounts[$this->activeCartId]);
        $this->refreshCart();
        $this->refreshActiveCarts();

        Notification::make()
            ->title('Savat tozalandi')
            ->success()
            ->send();
    }

    public function applyReceiptDiscount(float $amount): bool
    {
        $cartService  = app(CartService::class);
        $cart         = $cartService->all($this->activeCartId);
        $totals       = $cartService->totals($this->activeCartId);
        $currentTotal = (float) ($totals['amount'] ?? 0);

        if (empty($cart) || $currentTotal <= 0) {
            return false;
        }

        // Restore any item prices previously altered by receipt discount
        $carts   = session('pos_carts', []);
        $items   = $carts[$this->activeCartId] ?? [];
        $changed = false;
        foreach ($items as $id => $item) {
            $product = Product::find($id);
            if ($product && isset($this->cartReceiptDiscounts[$this->activeCartId])) {
                $items[$id]['price'] = (float) $product->price;
                $changed             = true;
            }
        }
        if ($changed) {
            $carts[$this->activeCartId] = $items;
            session()->put('pos_carts', $carts);
            $totals       = $cartService->totals($this->activeCartId);
            $currentTotal = (float) ($totals['amount'] ?? 0);
        }

        $amount = min($currentTotal, max(0, round($amount, 2)));
        if ($amount <= 0) {
            return false;
        }

        $this->cartReceiptDiscounts[$this->activeCartId] = $amount;
        $this->refreshCart();
        $this->refreshActiveCarts();

        Notification::make()
            ->title('Butun chekka chegirma qo‘llandi')
            ->success()
            ->send();

        return true;
    }

    public function resetReceiptDiscount(): bool
    {
        $cartService = app(CartService::class);
        $carts       = session('pos_carts', []);
        $items       = $carts[$this->activeCartId] ?? [];
        $changed     = false;

        foreach ($items as $id => $item) {
            $product = Product::find($id);
            if ($product && isset($this->cartReceiptDiscounts[$this->activeCartId])) {
                $items[$id]['price'] = (float) $product->price;
                $changed             = true;
            }
        }
        if ($changed) {
            $carts[$this->activeCartId] = $items;
            session()->put('pos_carts', $carts);
        }

        unset($this->cartReceiptDiscounts[$this->activeCartId]);
        $this->refreshCart();
        $this->refreshActiveCarts();

        Notification::make()
            ->title('Chek chegirmasi olib tashlandi')
            ->info()
            ->send();

        return true;
    }

    public function refreshCart(): void
    {
        parent::refreshCart();

        if (empty($this->cart)) {
            unset($this->cartReceiptDiscounts[$this->activeCartId]);

            return;
        }

        $receiptDiscount = (float) ($this->cartReceiptDiscounts[$this->activeCartId] ?? 0);
        if ($receiptDiscount > 0) {
            $rawAmount              = (float) ($this->totals['amount'] ?? 0);
            $this->totals['amount'] = max(0, round($rawAmount - $receiptDiscount, 2));
        }
    }

    public function refreshActiveCarts(): void
    {
        parent::refreshActiveCarts();

        foreach ($this->activeCarts as $cartId => $data) {
            $disc = (float) ($this->cartReceiptDiscounts[$cartId] ?? 0);
            if ($disc > 0 && isset($this->activeCarts[$cartId]['amount'])) {
                $this->activeCarts[$cartId]['amount'] = max(0, round($this->activeCarts[$cartId]['amount'] - $disc, 2));
            }
        }
    }

    public function checkout()
    {
        $receiptDiscount = (float) ($this->cartReceiptDiscounts[$this->activeCartId] ?? 0);
        $cartService     = app(CartService::class);
        $rawCart         = $cartService->all($this->activeCartId);
        $rawTotals       = $cartService->totals($this->activeCartId);
        $currentTotal    = (float) ($rawTotals['amount'] ?? 0);

        if ($receiptDiscount > 0 && $currentTotal > 0 && !empty($rawCart)) {
            $effectiveTotal = max(0, round($currentTotal - $receiptDiscount, 2));
            $ratio          = $effectiveTotal / $currentTotal;

            $carts       = session('pos_carts', []);
            $items       = $carts[$this->activeCartId] ?? [];
            $accumulated = 0;
            $itemKeys    = array_keys($items);
            $lastIndex   = count($itemKeys) - 1;

            foreach ($itemKeys as $idx => $itemId) {
                $qty           = (float) ($items[$itemId]['qty'] ?? 1);
                $originalPrice = (float) ($items[$itemId]['price'] ?? 0);
                if ($idx === $lastIndex) {
                    $remainingTarget = max(0, $effectiveTotal - $accumulated);
                    $newPrice        = $qty > 0 ? round($remainingTarget / $qty, 2) : 0;
                } else {
                    $newPrice = round($originalPrice * $ratio, 2);
                    $accumulated += $newPrice * $qty;
                }
                $items[$itemId]['price'] = $newPrice;
            }

            $carts[$this->activeCartId] = $items;
            session()->put('pos_carts', $carts);

            try {
                $result = parent::checkout();
                if ($result !== false) {
                    unset($this->cartReceiptDiscounts[$this->activeCartId]);
                } else {
                    $carts                      = session('pos_carts', []);
                    $carts[$this->activeCartId] = $rawCart;
                    session()->put('pos_carts', $carts);
                    $this->refreshCart();
                }

                return $result;
            } catch (\Throwable $e) {
                $carts                      = session('pos_carts', []);
                $carts[$this->activeCartId] = $rawCart;
                session()->put('pos_carts', $carts);
                $this->refreshCart();

                throw $e;
            }
        }

        $result = parent::checkout();
        if ($result !== false) {
            unset($this->cartReceiptDiscounts[$this->activeCartId]);
        }

        return $result;
    }

    public function canSeeCostPrice(): bool
    {
        return auth()->user()?->can('view_mobile_sales_cost_price') ?? false;
    }

    public function render(): View
    {
        $products = Product::query()->with(['category', 'media'])
            ->when(trim($this->search), fn ($query, $search) => $query->where(fn ($query) => $query
                ->whereLike('name', '%' . $search . '%')->orWhereLike('barcode', '%' . $search . '%')))
            ->when($this->mobileCategory, fn ($query, $category) => $query->where('category_id', $category))
            ->orderBy('name')->paginate(10);

        return view('livewire.mobile.sales', [
            'mobileProducts'       => $products,
            'mobileCategories'     => Category::query()->whereHas('products')->orderBy('name')->get(),
            'mobileCartProducts'   => empty($this->cart) ? collect() : Product::query()->with(['media', 'productStocks'])->whereKey(array_column($this->cart, 'id'))->get()->keyBy('id'),
            'mobileStocks'         => collect($this->stockOptions),
            'cartReceiptDiscounts' => $this->cartReceiptDiscounts,
            'canSeeCostPrice'      => $this->canSeeCostPrice(),
        ])->layout('mobile.sales-layout');
    }
}
