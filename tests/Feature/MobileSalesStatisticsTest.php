<?php

beforeAll(function (): void {
    $process = new Symfony\Component\Process\Process(['node', 'tests/Support/mobile-statistics.mjs'], dirname(__DIR__, 2));
    $process->setTimeout(60)->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    $GLOBALS['mobileStatisticsViews'] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
});

it('renders mobile statistics using supplied values without recalculating business metrics', function (): void {
    $html = $GLOBALS['mobileStatisticsViews']['ready'];
    expect($html)->toContain('Sotuv statistikasi', '12 450 000 so‘m', '+18.4%', '42 ta', '296 428.57', '3 200 000 so‘m', 'Sotuv dinamikasi', 'To‘lov turlari', 'Top mahsulotlar', 'Dior Sauvage', '3 600 000 so‘m')
        ->not->toContain('12,450,000', 'Statistika ma’lumotlari hali ulanmagan', 'Marja', '25.7%');
});

it('distinguishes missing integration from an actual empty report', function (): void {
    expect($GLOBALS['mobileStatisticsViews']['unavailable'])->toContain('Statistika ma’lumotlari hali ulanmagan')
        ->not->toContain('12 450 000', 'Sotuv ma’lumotlari topilmadi', '0 so‘m');
    expect($GLOBALS['mobileStatisticsViews']['empty'])->toContain('Sotuv ma’lumotlari topilmadi', 'Boshqa davrni tanlang', '0 so‘m')
        ->not->toContain('Statistika ma’lumotlari hali ulanmagan', 'NaN', 'Infinity');
});

it('renders skeleton and actionable error states', function (): void {
    expect($GLOBALS['mobileStatisticsViews']['loading'])->toContain('aria-busy="true"', 'statistics-skeleton', 'Sotuv statistikasi yuklanmoqda')
        ->not->toContain('12 450 000', 'Statistika ma’lumotlari hali ulanmagan');
    expect($GLOBALS['mobileStatisticsViews']['error'])->toContain('role="alert"', 'Statistikani yuklab bo‘lmadi', 'Qayta urinish');
});

it('does not display another store or another period report', function (string $state): void {
    expect($GLOBALS['mobileStatisticsViews'][$state])->toContain('Statistika ma’lumotlari hali ulanmagan')
        ->not->toContain('12 450 000', 'Dior Sauvage');
})->with(['otherStore', 'otherPeriod']);

it('does not substitute zero for withheld profit or display margin', function (): void {
    expect($GLOBALS['mobileStatisticsViews']['restricted'])->toContain('Foyda', 'Ma’lumot mavjud emas')
        ->not->toContain('3 200 000', '25.7%', 'Marja');
});

it('renders keyboard accessible charts with tabular alternatives and payment progress bars', function (): void {
    expect($GLOBALS['mobileStatisticsViews']['ready'])->toContain('tabindex="0"', 'Qiymatlarni ro‘yxatda ko‘rish', 'role="progressbar"', 'Naqd', 'Karta', 'O‘tkazma', 'Qarz');
    expect($GLOBALS['mobileStatisticsViews']['negative'])->toContain('is-negative', '-8.2%')->not->toContain('NaN', 'Infinity');
    expect($GLOBALS['mobileStatisticsViews']['singlePoint'])->toContain('cx="160"')->not->toContain('NaN', 'Infinity');
});
