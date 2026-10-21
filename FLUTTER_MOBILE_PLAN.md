# Flutter mobil ilova: imkoniyatlar, offline ishlash va bajarish rejasi

## 1. Hujjat holati va maqsad

- Sana: 2026-10-21. Loyiha: `babyparfume`.
- Holat: **to‘liq yakunlanmagan**. 2026-10-21 kod auditi va tuzatishlari §17 da. Backend va Flutter offline oqimlari tuzatildi; real Android/iPhone qabul sinovlari, eski lokal baza migratsiyasi, ayrim parity/sync bandlari va release ochiq. Printer foydalanuvchi qarori bilan hozircha kechiktirilgan.
- Maqsad: hozirgi `/mobile` interfeysining barcha foydalanuvchi imkoniyatlarini Flutter Android/iOS ilovasiga ko‘chirish, qarzdorlar bilan offline ishlash, mahsulotlarni offline qidirish va sotuvni offline yakunlashni ta’minlash.
- Cheklov: mavjud web admin, `/mobile`, `/mobile/api/v1/*`, Livewire sessiyalari va biznes qoidalari buzilmasin.
- Joriy holat uchun §17 ustuvor; avvalgi ish jurnali tasdiqlanmagan da’volarni ham o‘z ichiga oladi. Ushbu hujjat davom ettirish nuqtasi: keyingi AI avval uni va tegishli `AGENTS.md` ko‘rsatmalarini o‘qiydi.
- Belgilanmagan texnik tanlovlar tasdiqlangan qaror deb qabul qilinmaydi. Paketlar, yangi asosiy papkalar va bog‘liqliklar uchun repository qoidalariga muvofiq oldindan ruxsat olinadi.

## 2. Foydalanuvchi tasdiqlagan talablar

| ID | Qaror |
| --- | --- |
| R-01 | Ilovani hozir web tizimidan foydalanayotgan do‘kon egalari ishlatadi; mavjud akkaunt va ruxsatlar saqlanadi. |
| R-02 | Android va iPhone uchun Flutter ilova kerak. Android uchun imkon bo‘lsa Google Play, muqobil ravishda imzolangan APK tarqatiladi. |
| R-03 | Qarzdorlar bo‘yicha hozirgi mobil interfeysdagi barcha funksiyalar internet bo‘lmaganda ham ishlashi kerak. |
| R-04 | Mahsulotlarni internet bo‘lmaganda ham nomi va barcode orqali qidirish kerak. |
| R-05 | Tovar qoldig‘i 0 bo‘lsa ham savdoni davom ettirish mumkin; qoldiq yetishmasligi savdoni bloklamasin. |
| R-06 | Qarz bo‘yicha sinxronlash ziddiyatlarini administrator hal qiladi. |
| R-07 | Bitta akkaunt bir nechta telefonda parallel ishlashi mumkin. |
| R-08 | Oxirgi muvaffaqiyatli server tekshiruvidan so‘ng ko‘pi bilan 10 soat offline ishlashga ruxsat beriladi. |
| R-09 | Bluetooth printer kerak. Model noma’lum; chek 58 mm yoki 80 mm bo‘lishi mumkin. |
| R-10 | Barcode skanerlash tiniq, aniq va takroriy noto‘g‘ri qo‘shishlarsiz ishlashi kerak. |
| R-11 | Hozirgi mobil dizayn saqlanadi; interfeys faqat o‘zbek tilida bo‘ladi. |
| R-12 | Web admin va mavjud mobil web routelarining ishlashi o‘zgarmasin; yangi imkoniyatlar qo‘shimcha qatlam sifatida kiritilsin. |
| R-13 | Sotuv ham 10 soatlik offline ruxsat ichida to‘liq yakunlansin: mavjud to‘lov turlari, chegirmalar, mijoz/qarz, lokal chek va keyin takrorlanmaydigan server sinxronlash majburiy. |
| R-14 | Flutter shu repository ichidagi `mobile_app/` papkasida yoziladi; alohida repository yoki submodule yaratilmaydi. |

### 2.1. Talabni kengaytirib yubormaslik

- Barcha hozirgi mobil imkoniyatlar Flutter’da onlayn ishlashi majburiy.
- Offline majburiy scope: qarzdorlar moduli to‘liq, mahsulot qidiruvi/uning lokal ma’lumotlari va sotuvni yakunlash, jumladan chek chiqarish.
- Mahsulot yaratish/tahrirlash/o‘chirish va butun do‘konning jonli statistikasi offline talab sifatida tasdiqlanmagan. Birinchi versiyada mahsulot mutationlari onlayn; offline statistika faqat belgilangan snapshot va lokal amallarni ajratib ko‘rsatadi.
- Qoldiq 0/yetishmasa ham onlayn va offline savdo davom etadi. Bu ruxsat mahsulot, ombor, narx yoki do‘konning boshqa validatsiyalarini bekor qilmaydi.
- Offline savdo telefonda yakunlangan hisoblanadi, lekin server qabul qilguncha `pending` holatida saqlanadi; xaridorga chek berish va server tasdig‘i bir xil hodisa deb ko‘rsatilmaydi.
- Telefon boshqa qurilmalar va web’dagi yangi ma’lumotlarni internet yo‘qligida bilolmaydi. Offline raqamlar lokal holat ekanligi ochiq ko‘rsatiladi.
- Lokal bazani avval yuklamasdan, birinchi marta internetsiz kirish yoki butun katalogni ko‘rish va’da qilinmaydi.
- Sinxronlangan savdo/qarz/to‘lovlarning umumiy bazada va web’da ko‘rinishi kutilgan natija; “web’ga ta’sir qilmaslik” ma’lumotlarni bir-biridan uzib qo‘yish degani emas.

## 3. Hozirgi koddan aniqlangan holat

| Qism | Manba | Migratsiya uchun ahamiyati |
| --- | --- | --- |
| Mobil routelar | `routes/mobile.php`, `routes/web.php` | `/mobile/api/v1/*` va `/mobile/sales-workspace` mavjud; eski shartnomalar saqlanadi. |
| Mobil UI | `resources/js/mobile/`, `resources/views/mobile/` | Vue sahifalar, mobil sotuv Blade shabloni va CSS dizayn uchun asos. |
| Kirish va do‘kon | `app/Http/Controllers/Mobile/SessionController.php` | Cookie/CSRF ishlatiladi; do‘kon tanlash `users.current_store_id` ni o‘zgartiradi. Flutter bunday qilmasin. |
| Hozirgi API mijoz | `resources/js/mobile/api.ts` | Blade’dan CSRF/base URL oladi; Flutter HTTP mijozi alohida yoziladi. |
| Do‘kon tekshiruvi | `app/Http/Middleware/EnsureMobileStore.php` | `current_store_id` bilan bog‘langan. Yangi token API uchun mustaqil do‘kon konteksti zarur. |
| Global do‘kon scope | `app/Models/Traits/HasCurrentStoreScope.php` | Modellar joriy user do‘koniga bog‘liq. Yangi API uchun xavfsiz request-scoped yechim alohida audit qilinadi. |
| Mahsulotlar | `app/Http/Controllers/Mobile/ProductController.php` | Ro‘yxat, qidiruv, lookup, yaratish, tahrirlash, o‘chirish, barcode va form options mavjud. |
| Qarzdorlar | `app/Http/Controllers/Mobile/DebtorController.php` | Ro‘yxat, yaratish, tafsilot, tahrirlash, tranzaksiyalar va to‘lov/qarz qo‘shish mavjud. |
| Qarz biznes qoidalari | `app/Services/MobileDebtService.php` | Yaratish va tranzaksiyalarda request UUID/hash orqali takrorni aniqlash bor; barcha tahrir/media amallari uchun umumiy sync protokoli hali yo‘q. |
| Qarz request tracking | `database/migrations/2026_10_15_105042_add_mobile_request_tracking_to_debtor_transactions_table.php` | Bor mexanizm qayta ishlatilishi mumkin, lekin u to‘liq offline sync bilan teng emas. |
| Qarzdor fotosi | `app/Http/Controllers/DebtorPhotoController.php`, `app/Services/DebtorPhotoService.php` | Hozir sessiyali himoya bor; Flutter uchun ham himoyalangan tokenli media kerak. |
| Sotuv ekrani | `resources/js/mobile/pages/Sales.vue` | Livewire sahifani iframe orqali ochadi; Flutter’da iframe/WebView ishlatmasdan qayta yoziladi. |
| Mobil sotuv | `app/Livewire/Mobile/Sales.php` | Savatlar, kategoriya, chegirmalar, tannarx ruxsati va mobil checkout xususiyatlari mavjud. |
| Umumiy POS | `app/Filament/Pages/Pos.php`, `app/Services/CartService.php` | Checkout, to‘lov, mijoz, ombor/razmer, chek va sessiyali savatlar. Biznes mantiqning katta qismi UI klassida. |
| POS sessiya izolatsiyasi | `app/Http/Middleware/MobileSalesSession.php` | Desktop va mobil web savatlarining mavjud ajratilishi o‘zgartirilmaydi. |
| Statistika | `app/Services/MobileSalesStatisticsService.php` | Davr, savdo, foyda, marja, trend, to‘lovlar va ruxsatli top mahsulotlar. |
| Ruxsatlar | Policies, Shield, `SessionController`, mobile Resources | Tannarx, statistika, yaratish/tahrirlash/o‘chirish ruxsatlari serverda saqlanadi. |
| Obuna ogohlantirishi | `SessionController.php`, `resources/js/mobile/types.ts` | Hozirgi ogohlantirish matni/holati Flutter’da ham ko‘rsatiladi; yangi bloklash siyosati o‘ylab topilmaydi. |

Muhim: `Pos` dan meros olingan metod mavjudligi uning mobil UI’da ochiqligini bildirmaydi. Qaytarish/almashtirish kabi imkoniyatlar faqat hozirgi mobil ekranda haqiqatan mavjudligi tasdiqlansa parity scope’ga kiradi; web-only imkoniyatlar avtomatik qo‘shilmaydi.

## 4. Majburiy himoya chegaralari

1. Eski route nomlari, URL, HTTP metodlari, response formatlari, login/CSRF va validatsiya shartlari saqlansin.
2. Yangi token guard/middleware eski web guard va Livewire autentifikatsiyasini almashtirmasin.
3. Flutter do‘konni har bir so‘rovda aniq yuboradi; server a’zolik/ruxsatni qayta tekshiradi. `users.current_store_id` orqali qurilmalar bir-birini almashtirmasin.
4. Mavjud global scope’ni butun ilova bo‘yicha o‘chirib tashlash taqiqlanadi. Yangi API ichida chetlab o‘tilgan har bir scope o‘rniga aniq do‘kon va foydalanuvchi cheklovi bo‘lsin.
5. Eski `MobileDebtService` ni shunchaki chaqirish yetarli emas: uning `lockStore` va boshqa `current_store_id` bog‘liqliklari tekshirilsin.
6. Bir xil moliyaviy qoida serverdagi umumiy domain qatlamida qoladi; Flutter UI va server ikki mustaqil hisoblash haqiqatiga aylanmasin.
7. POS refaktori faqat parity testlari va alohida bosqich bilan; eski checkoutni bir yo‘la almashtirish taqiqlanadi.
8. Migrations qo‘shimcha va backward-compatible bo‘lsin. Eski ustunni o‘chirish/nomlash/turini o‘zgartirish, eski ma’lumotlarni tozalash ushbu rejaga kirmaydi.
9. Yangi API/sync/admin ziddiyat ekrani alohida feature flag bilan yoqilsin; o‘chirilsa eski tizim ishlashda davom etsin.
10. SMS/Telegram, stock, qarz va cheklar retry sabab ikki marta yaratilmasin. Mavjud bildirishnoma oqimi regressiya testlari bilan himoyalansin.
11. Qabul qilingan server operatsiyasi Flutter uzilib qolganligi uchun qayta bajarilmasin; ma’lumot serverda saqlangan, javob yo‘qolgan holat qo‘llab-quvvatlansin.
12. Faqat hujjat tuzish bosqichida dependencies, production DB, konfiguratsiya va ilova kodi o‘zgartirilmasin.

## 5. Imkoniyatlarning online/offline jadvali

| Imkoniyat | Onlayn Flutter | Offline, lease ichida | Izoh |
| --- | --- | --- | --- |
| Birinchi login, yangi qurilma | Ha | Yo‘q | Akkaunt va qurilma serverda tasdiqlanadi. |
| Avval kirilgan akkauntni ochish | Ha | Ha | Lokal himoya va 10 soatlik lease bilan. |
| Ruxsatlar/obuna ogohlantirishi | Jonli | Oxirgi tasdiqlangan nusxa | Yangilanish vaqti ko‘rsatiladi. |
| Do‘kon tanlash | Ruxsatli barcha do‘kon | Faqat oldindan tayyor yuklangan do‘kon | Har do‘kon alohida cache/outbox. |
| Qarzdor ro‘yxati/qidiruvi | Ha | Ha | Aktiv, yopilgan, muddati o‘tgan; ism va telefon. |
| Qarz jami va filtr sonlari | Server holati | Lokal ko‘rinish | Pending/conflict ta’siri ochiq ajratiladi. |
| Qarzdor tafsiloti va tarixi | Ha | Ha | Offline-ready do‘kon uchun to‘liq kerakli tarix yuklanadi. |
| Qarzdor yaratish | Ha | Ha | Lokal UUID, idempotent sync, telefon kolliziyasi tekshiruvi. |
| Ism/telefon/sana/izoh tahriri | Ha | Ha | Eskirgan versiya administratorga yuboriladi. |
| Qarz summasini tahrirlash | Ha | Ha | Oddiy overwrite emas; auditli correction va ziddiyat qoidasi bilan. |
| Qarz qo‘shish va to‘lov olish | Ha | Ha | Lokal pending; server tekshiruvidan keyin tasdiqlangan. |
| Qarzdor foto ko‘rish | Ha | Yuklangan foto | Yetishmagan foto uchun aniq placeholder. |
| Foto qo‘shish/almashtirish/o‘chirish | Ha | Ha | Fayl lokal xavfsiz saqlanadi, upload qayta davom etadi. |
| Mahsulot nomi/barcode qidiruvi | Ha | Ha | Birinchi sahifa emas, ruxsatli to‘liq katalog; katta-kichik harf farqsiz. |
| Mahsulot tafsiloti/razmer/ombor | Ha | Yuklangan nusxa | Qoldiq/narxning oxirgi yangilanishi ko‘rsatiladi. |
| Mahsulot foto/thumbnail | Ha | Yuklanganlari | Barcha original rasmni majburan yuklash shart emas. |
| Mahsulot yaratish/tahrirlash/o‘chirish | Ha | Yo‘q | Form draft saqlanishi mumkin; mutation onlayn. |
| Mahsulot barcode generatsiyasi | Ha | Yo‘q | Server unique tekshiruvi saqlanadi. |
| Kamera bilan barcode skanerlash | Ha | Ha | Offline qidiruv lokal katalogdan. |
| POS savatlari va draft | Ha | Ha | Bir nechta savat va metadata app restartdan keyin tiklanadi. |
| Mijoz qidirish/tanlash/yaratish | Ha | Ha | Ruxsatli lokal ma’lumotlar, yangi mijoz uchun UUID va server ID mapping. |
| Savdoni yakunlash/to‘lov/qarzga sotuv | Ha | Ha | Lokal atomik checkout, pending outbox va keyin server tasdig‘i. |
| Mavjud to‘lov turlari va chegirmalar | Ha | Ha | Server bilan bir xil qoidalar; karta/transfer yozuvi bank avtorizatsiyasi degani emas. |
| Qoldiq 0 bo‘lganda sotuv | Ha | Ha | Mahsulotga ruxsat va ombor bog‘lanishi baribir tekshiriladi. |
| Sotuv cheki | Ha | Ha | Lokal unique chek ID va sync holati; server qabul qilganda server raqami bilan bog‘lanadi. |
| Bluetooth bosma/ulashish | Ha | Lokal chek bo‘lsa ha | Platforma va model mosligi alohida sinovdan o‘tadi. |
| Statistika/grafiklar | Ha | Oxirgi yuklangan hisobot | Global real-time yoki hali yuklanmagan davr deb ko‘rsatilmaydi. |
| Admin ziddiyat qarori | Ha | Yo‘q | Telefon qarorni keyingi sync’da oladi. |

## 6. Taklif etiladigan arxitektura

```text
Flutter Android / iOS
  O‘zbekcha UI, hozirgi mobil dizayn
  Use case / repository qatlami
  Himoyalangan lokal SQL baza + foto fayllari
  Outbox + sync coordinator + qurilma autentifikatsiyasi
                         |
                       HTTPS
                         |
Laravel: alohida versiyalangan Flutter API
  Token autentifikatsiya + request-scoped store + policies
  Bootstrap / delta / push / operation status / conflicts / media
  UI va sessiyaga bog‘liq bo‘lmagan domain xizmatlar
                         |
             Mavjud umumiy ma’lumotlar bazasi
                         |
     Mavjud Filament web admin va mobil web o‘z yo‘lida qoladi
```

- Yangi API uchun taklif: `/api/flutter/v1/*`; yakuniy prefix PH-01 da qayd qilinadi, eski `/mobile/api/v1/*` saqlanadi.
- Flutter joylashuvi tasdiqlangan: ushbu repository ildizidagi `mobile_app/`. Foydalanuvchi shu yangi asosiy papkani yaratishga ruxsat berdi; alohida repository yoki submodule kerak emas. Ushbu hujjat yangilanishi Flutter scaffoldini yaratish bosqichi emas.
- Laravel va Flutter bitta Git repository’da yuritiladi, ammo mustaqil dependency/build jarayonlariga ega: Flutter `mobile_app/pubspec.yaml`, Laravel `composer.json`, mavjud web esa `package.json` orqali boshqariladi.
- Flutter `android/`, `ios/`, `lib/` va testlari `mobile_app/` ichida bo‘ladi. Flutter build/cache va signing sirlarini Git’dan chiqarish qoidalari PH04-01 da qo‘shiladi; mavjud Laravel/Vite build va deploy oqimlari o‘zgartirilmaydi.
- Android/iOS ilovasi papka bir joyda bo‘lgani uchun Laravel/PHP’ni telefonda ishga tushirmaydi; backend bilan HTTPS API orqali ishlaydi, offline amallar esa telefondagi lokal bazada bajariladi.

```text
babyparfume/
  app/                 Laravel backend
  resources/           Mavjud web va mobil web
  routes/              Mavjud va qo‘shimcha API routelar
  mobile_app/          Flutter loyiha, PH04-01 da yaratiladi
    lib/
    android/
    ios/
    test/
    pubspec.yaml
  FLUTTER_MOBILE_PLAN.md
```
- Flutter state management, routing, HTTP, SQL/encryption, secure storage, scanner, printer va chart paketlari PH-01 da tanlanadi. Tasdiqsiz dependencies qo‘shilmaydi.
- Lokal baza schema migrations bilan; PII va navbatdagi moliyaviy payloadlar himoyalansin. Tokenlar oddiy preferences/loglarda saqlanmasin.
- UI faqat lokal repository’dan holat oladi; server natijasi avval lokal bazaga atomik tushadi, keyin ekran yangilanadi.
- Android APK iPhone’da o‘rnatilmaydi. iOS uchun alohida signing va Apple tarqatish jarayoni rejalashtiriladi.

### 6.1. PH-01 kontraktining birinchi versiyasi — 2026-10-21

Foydalanuvchi yangi Flutter SDK va yuqorida taklif qilingan dependencies’ni o‘rnatishga, vazifalarni tartib bilan bajarishga rozilik berdi. Bu production migratsiya/deploy, yangi moliyaviy siyosat yoki marketplace nashriga rozilik emas. Printer modeli va signing hisobi hali noma’lum. Quyidagi kontraktda **amalga oshirilgan** va **rejalangan** qismlar alohida ko‘rsatilgan.

#### Environment va autentifikatsiya

- Yangi prefix: `/api/flutter/v1`; eski `/mobile/api/v1` o‘zgarishsiz. Environmentlar `local`, `staging`, `production`; lokal baza/outbox namespace kaliti `origin + protocol_version + user_id + store_id`. Boshqa origin’ga pending operatsiya yuborilmaydi.
- Laravel/Vite va Flutter mustaqil build/deploy qilinadi. Flutter build’dagi API origin ommaviy konfiguratsiya; token/signing kalitlari build parametriga kiritilmaydi. Staging/production HTTPS originlari release sozlamasida beriladi; `baby.lc` telefondan ishlaydigan production origin deb olinmaydi.
- Yangi API default `FLUTTER_API_ENABLED=false` bilan 404 beradi. `true` qilishdan oldin additive migration, regressiya va environment tekshiruvi bajariladi. Hozir hech qanday biznes DB migratsiyasi yoki deploy qilinmadi.
- Sanctum `4.3.3` lock qilindi. Faqat bearer token qabul qilinadi; cookie/CSRF fallback yo‘q. Default web guard `web` bo‘lib qoladi. Device UUID autentifikatsiya siri emas; bir UUID boshqa akkaunt sessiyasini egallay olmaydi.
- Login har qurilma/akkaunt juftligi uchun alohida token beradi, shu qurilmadagi oldingi tokenni aylantiradi. Token umri 30 kun; bu 10 soatlik offline lease emas. `logout` faqat joriy tokenni bekor qiladi; boshqa telefonlar, web login va savatlar saqlanadi. Eski logout yangi aylantirilgan tokenni bekor qilmasligi test qilinadi.
- Do‘kon URL’dagi `stores/{store}` va serverdagi joriy membership’dan olinadi. `users.current_store_id`, cookie, client body yoki eski `X-Mobile-Store` do‘konni belgilamaydi. Entity endpointlarida `Gate::forUser($request->user())` va explicit store query ishlatiladi; default web Gate/global model scope’iga tayanilmaydi.

#### Hozir amalga oshirilgan auth endpointlari

| Request | Javob |
| --- | --- |
| `GET /health` | `200 {"data":{"protocol_version":1,"authentication":"bearer","offline_max_seconds":36000,"server_time":"…","features":{"device_sessions":true,"products":false,"debtors":false,"sales":false,"sync":false,"offline_lease":false}}}` |
| `POST /login` | Body: `{"email":"demo@example.test","password":"<secret>","device_uuid":"71503d44-a786-431c-b8fb-4f0e3f78fbcd","device_name":"Android telefon","platform":"android"}`. `201 {"data":{"token":"<once>","token_type":"Bearer","expires_at":"…","device_uuid":"…","offline_ready":false}}` |
| `GET /session` | Bearer required. `200 {"data":{"user":{"id":"1","name":"…"},"device":{"uuid":"…","name":"…","platform":"android"},"stores":[{"id":"2","name":"…"}],"selected_store_id":null,"server_time":"…","offline_ready":false}}` |
| `GET /stores/2/session` | Xuddi shu session, `selected_store_id:"2"`; faqat joriy membership bo‘lsa. User yozuvi o‘zgarmaydi. |
| `POST /logout` | Bearer required, `204`, faqat shu sessiya revoke. |
| `DELETE /devices/{device_uuid}` | Bearer required, faqat o‘z akkauntidagi device, `204`; begona UUID uchun 404. Bu endpoint qurilmani revoke qiladi, uning serverga yuborilgan moliyaviy yozuvlarini o‘chirmaydi. |

#### Rejalangan sync kontrakti — hozir bu endpointlar mavjud emas

- `POST /stores/{store}/bootstrap`: body `{"schema_version":1}`; javob `snapshot_id`, opaque `watermark`, majburiy dataset/count manifest va server vaqti. `GET /stores/{store}/bootstrap/{snapshot_id}?cursor=<opaque>&limit=500` dataset sahifasini qaytaradi. `snapshot_id` actor/store/device/ruxsat snapshotiga bog‘lanadi. Snapshot materializatsiyasi yoki o‘qish izchilligi PH-03 da isbotlanmaguncha endpoint/offline-ready yoqilmaydi.
- `GET /stores/{store}/delta?cursor=<opaque>&limit=500`: `{"data":{"changes":[{"entity":"debtor","id":"42","revision":"18","action":"upsert","value":{}}],"next_cursor":"…","has_more":false}}`. Delete/restore/store-dan chiqish ham shu oqimga kiradi; `updated_at`ning o‘zi revision emas. Retention tashqarisidagi cursor 410 `cursor_reset_required`; client pending navbatini o‘chirmasdan qayta bootstrap qiladi.
- `POST /stores/{store}/lease`: completed snapshot/delta holati tekshirilgach, user/device/store/permission revision, `issued_at`, `offline_until`, `lease_id`, `key_id` va server imzosini qaytaradi. Maksimum 36000 soniya. Lease imzolash/kalit aylantirish va native monotonic/boot tekshiruvi hali yozilmagan; health’da `offline_lease=false` qoladi.
- `POST /stores/{store}/operations`: `{"schema_version":1,"operations":[{"operation_id":"<uuid>","device_id":"<uuid>","store_id":"2","entity_type":"debtor","entity_id":"42","local_id":null,"operation_type":"payment","base_revision":"17","dependencies":[],"sequence":12,"occurred_at":"2026-10-21T10:00:00Z","lease_id":"<uuid>","payload":{"amount":"10000","currency":"UZS","scale":0,"note":"…"}}]}`. Har itemga alohida `applied|conflict|blocked|rejected`, canonical revision, ID mapping va chek reference qaytadi; batch muvaffaqiyati har item qabul qilindi degani emas.
- `GET /stores/{store}/operations/{operation_id}`: faqat tegishli actor/store ko‘ra oladigan status; qayta yuborishdan oldin javobi yo‘qolgan amal tekshiriladi. Shu UUID boshqa payload/actor bilan ishlatilsa 409 `idempotency_mismatch`; avvalgi moliyaviy ta’sir takrorlanmaydi.
- `GET /stores/{store}/conflicts?cursor=<opaque>`: shu account/store uchun holat va admin qarori; admin mutation alohida Filament permission bilan. `{"operation_id":"…","status":"resolved","resolution":"applied","canonical_revision":"19","reason":"…"}` kelgach lokal overlay yangilanadi. Boshqa userning conflict payloadi ochilmaydi.
- `POST /stores/{store}/media`: multipart upload, `operation_id`, parent local/server ID va checksum; javob actor/store/parent bilan bog‘langan `media_id`. `GET /stores/{store}/media/{media_id}` bearer va entity policy bilan stream qiladi. Original disk yo‘li, public PII URL yoki boshqa do‘kon fayli berilmaydi; binary fayl JSON batch ichiga qo‘yilmaydi.
- Offline sale alohida qisman itemlar emas, bitta `operation_type:"sale.create"` envelope. Payload: `{"local_sale_id":"<uuid>","client":{"server_id":"7","local_id":null},"items":[{"product_id":"12","product_size_id":null,"stock_id":"3","quantity":1,"unit_price":"24000.00","discount":"0.00"}],"payment":{"type":"cash","cash":"24000.00","card":"0.00"},"receipt_discount":"0.00","currency":"UZS","scale":2}`. Mijozsiz holat `client:null`; qolgan payment turlari §10 qoidalarini saqlaydi. Server total/cost/stock/qarz ta’sirini qayta hisoblaydi; sale/items/payment/qarz/stock bir tranzaksiyada yoki butun aggregate conflict.

#### ID, pul, vaqt, limit va xatolar

- Yangi API’dagi server ID/revisionlar JSON satr; lokal/entity/operation/device ID — UUID. Eski API’dagi numeric IDlar o‘zgarmaydi. Barcode ham satr, boshidagi nol saqlanadi.
- Amount JSON number/float emas, kanonik decimal satr. Hozirgi product/debtor UZS qiymatlari `scale:0`; sale/payment `decimal(15,2)` qiymatlari `scale:2`. Masalan qarz `"10000"`, savdo `"10000.50"`. Client lokal hisobda integer scaled units ishlatadi; scale/currency mos kelmasa rad etiladi. Yangi valyuta yoki yaxlitlash siyosati kiritilmaydi.
- Timestamp UTC RFC3339; biznes sanasi `YYYY-MM-DD` va backend `config('app.timezone')` bilan talqin qilinadi. Client timestamp server tartibi yoki permission tekshiruvi o‘rniga ishlatilmaydi. `server_time`dan anchor olinadi; clock/boot ishonchi yo‘qolsa yangi offline mutation bloklanadi, eski pending qoladi.
- Sync taklif limitlari: 50 operation / 256 KiB JSON batch, dataset sahifasi 1..500. Katta payload/foto alohida. Bular PH-03 testlari bilan enforce qilinmaguncha mavjud health’da qo‘llab-quvvatlanayotgan funksiya deb ko‘rsatilmaydi.
- Transport/domain xatosi: `{"error":{"code":"store_forbidden","message":"…"}}`. Hozirgi Form Request validationi Laravel JSON `{"message":"…","errors":{"email":["…"]}}` va 422 qaytaradi; Flutter ikkalasini taniydi. 401 qayta kirish; 403 ruxsat yo‘q; 404 yo‘q/yopiq; 409 conflict/replay mismatch; 410 cursor reset; 413 payload katta; 422 validation; 429 `Retry-After`; 5xx/timeouts retry bilan, operation UUID o‘zgarmaydi.
- Barcha yangi auth javoblari, shu jumladan xatolar, `private,no-store`; session cookie yoki CSRF qaytarmaydi. Token, telefon, foto va moliyaviy payload logga yozilmaydi.

#### UX va release chegaralari

- Pending bor logout: sync qilish yoki shifrlangan account-bound navbatni saqlab chiqish. Default silent discard yo‘q. Logout tokenni olib tashlaydi, boshqa akkauntga eski navbat ko‘rinmaydi/yuborilmaydi; qayta login faqat o‘sha account namespace’ni ochadi. Qurilmani/appni o‘chirish hali yuborilmagan ma’lumotni yo‘qotishi mumkinligi ko‘rsatiladi.
- Lease tugashi/clock ishonchsizligi: read-only lokal snapshot, pendingni ko‘rish va eski chekni qayta chop etish mumkin; yangi moliyaviy mutation onlayn tekshiruvgacha bloklanadi. Admin review navbati real pul faktini yo‘qotmaydi; ortiqcha to‘lovni avtomatik avans/refundga aylantirmaydi.
- Zero-stock: R-05 bo‘yicha sotuv davom etadi va mavjud stock saturation `max(0, available - quantity)` saqlanadi. Yetishmovchilik auditida so‘ralgan va mavjud miqdor qayd qilinadi; yashirin manfiy ombor yoki yangi moliyaviy posting yaratilmaydi.
- Release blockerlar: Google Play/Apple developer hisoblari va signing egaligi noma’lum; iOS uchun macOS/Xcode yo‘q; printer modeli, 58/80 mm va BLE/Classic protokoli noma’lum. Hisob/kalit yaratilmagan, nashr/deploy bajarilmagan. Bluetooth va physical camera/offline acceptance tekshirilmaguncha production-ready belgilanmaydi.

## 7. Autentifikatsiya, bir nechta qurilma va 10 soat qoidasi

### 7.1. Akkaunt va do‘kon izolatsiyasi

- Har qurilma alohida `device_id` va bekor qilinadigan token/session oladi; qurilma IDning o‘zi autentifikatsiya emas.
- Token autentifikatsiyasi uchun Sanctum taklif etiladi; o‘rnatish va guard sozlashdan oldin dependency roziligi olinadi.
- Tanlangan do‘kon qurilma lokal holatida va request kontekstida bo‘ladi, user yozuvini o‘zgartirmaydi.
- Cache/outbox kaliti kamida server muhiti + user + store; qurilma identifikatori sync auditida saqlanadi.
- Bir akkauntning A/B telefonlari turli do‘konlarda parallel ishlashi, web’da do‘kon almashtirilishi Flutter operatsiyasini boshqa do‘konga ko‘chirmasligi kerak.
- Qarzdor mijozi bir nechta do‘konda ishlatilishi mumkinligi tekshirilsin: `Client` uchun umumiy tahrir boshqa do‘kon ma’lumotiga ta’sir qilishi mumkin. Global mijozlar ro‘yxatini ruxsatsiz yuklashga yo‘l qo‘yilmasin.
- Akkaunt almashganda oldingi account navbati yuborilmasin, ma’lumotlari yangi account ekraniga chiqmasin.

### 7.2. Offline lease

- Birinchi login va har bir offline-ready do‘konning dastlabki tayyorlanishi onlayn bajariladi.
- Server qurilma/user/do‘kon/ruxsatlar bilan bog‘langan `issued_at` va `offline_until` beradi; lease muddati ko‘pi bilan 10 soat.
- Faqat server user, qurilma, do‘kon a’zoligi va ruxsatlarni muvaffaqiyatli qayta tekshirganida lease yangilanadi. Tarmoq belgisi, ilovani ochish yoki xato javob muddati uzaytirmaydi.
- Lease va API tokenning muddati alohida tushunchalar. Tarmoq xatosi 10 soat hisobini boshidan boshlamasin.
- Qurilma soati orqaga surilishi va qayta yuklanish orqali lease uzaymasin: server vaqti, monoton vaqt va konservativ qayta login siyosati test qilinsin.
- 10 soatdan keyin yangi biznes amallari va maxfiy ma’lumotga offline kirish to‘xtaydi; “Internetga ulanib hisobni tasdiqlang” ko‘rsatiladi. Oldin saqlangan navbat/fayllar o‘chmaydi.
- Muddat ichida yaratilgan operatsiyalar lease tugaganidan keyin qayta autentifikatsiya orqali yuborilishi mumkin; eski envelope o‘zgartirilmaydi.
- Serverga kelgan har bir amal joriy ruxsat/a’zolik bilan qayta tekshiriladi; bekor qilingan ruxsatni eski lease bilan chetlab o‘tib bo‘lmaydi.
- Offline qurilmaga ruxsat bekor qilinishini darhol yetkazish kafolatlanmaydi; bu 10 soatlik cheklovning ongli xavfi sifatida qayd qilinadi.
- Logout pending operatsiyalarni indamay o‘chirmasin. Tasdiqlash/ogohlantirish, accountga bog‘langan shifrlangan saqlash va qayta login tiklash oqimi bo‘lsin.
- App’ni o‘chirish yoki telefon yo‘qolishi serverga hali yuborilmagan ma’lumotni yo‘qotishi mumkin. Interfeysda pending soni va sync zarurligi ko‘rsatiladi; bu holat “to‘liq backup bor” deb va’da qilinmaydi.

## 8. Lokal ma’lumot va sinxronlash shartnomasi

### 8.1. Bootstrap va delta

- Har do‘kon uchun to‘liq ruxsatli mahsulot katalogi, qarzdorlar, kerakli tranzaksiya tarixi, ombor/razmer, ruxsatlar va konfiguratsiya paginated bootstrap bilan olinadi.
- Katta katalog/history hajmi o‘lchanadi; davom ettiriladigan download, disk nazorati, progress va atomik staging bo‘ladi. Birinchi 20 ta yozuv yuklangani “offline tayyor” emas.
- Majburiy tekst/raqam ma’lumotlari to‘liq kelmaguncha do‘kon offline-ready bo‘lmaydi; foto yuklanishining holati alohida.
- Snapshot watermark/cursor, server vaqti va schema versiyasi bilan qaytadi. Sahifalar davomida sodir bo‘lgan o‘zgarishlar yo‘qolmasin.
- Delta server tartibidagi barqaror cursor bilan; bir xil vaqtli o‘zgarishlar, soft delete, restore, fizik o‘chirish va do‘kon/ruxsatdan chiqishni tombstone bilan qamrasin.
- Web admin, eski mobile va importlar yozgan o‘zgarishlar ham delta/version mexanizmiga kirsin. Faqat Flutter yozuvlarini loglash yetarli emas.
- `updated_at`ning o‘zi kuchli revision emas. Barcha writerlar bilan ishlaydigan revision yoki tranzaksiya ichida tekshiriladigan aniq fingerprint strategiyasi tanlansin.
- Cursor eskirsa yoki retention tugasa, yangi snapshot olinadi; pending outbox saqlanadi va overlay qayta qo‘llanadi.
- Fon jobdagi kontekst/global scope sizib qolmasin; long-running workerda user/store holati keyingi jobga o‘tmasligi test qilinadi.

### 8.2. Outbox va operatsiya envelope’i

Har bir o‘zgartiruvchi amal kamida quyidagilarni saqlaydi:

| Maydon | Vazifa |
| --- | --- |
| `operation_id` | Qurilmada bir marta yaratiladigan barqaror UUID; retry’da o‘zgarmaydi. |
| `device_id`, `store_id` | Qurilma va do‘kon konteksti; user server tokenidan olinadi. |
| `entity_type`, `entity_id` / `local_id` | Server yozuvi yoki hali sync bo‘lmagan lokal yozuv. |
| `operation_type`, `payload`, `schema_version` | Amal turi, kanonik payload va protokol versiyasi. |
| `base_revision`, `dependencies` | Eskirgan ma’lumotni aniqlash va oldingi create/media amallariga bog‘liqlik. |
| `occurred_at`, `lease_id`, `sequence` | Offline vaqt, olingan lease va qurilmadagi tartib; client vaqtiga ko‘r-ko‘rona ishonilmaydi. |
| `status`, `attempts`, `next_retry_at`, `last_error` | Davom ettirish va foydalanuvchiga tushunarli holat. |

- Lokal o‘zgarish va outbox yozuvi bitta lokal tranzaksiyada saqlanadi. UI “saqlandi” desa app yopilganda ham yozuv tiklanishi kerak.
- Lokal/server ID mapping alohida saqlanadi; keyingi to‘lov va foto yaratish yozuvining server ID’sini kutadi.
- Bir entity amallari tartibli, mustaqil entitylar cheklangan parallel; conflictdan keyingi bog‘liq amallar to‘xtab turadi.
- Holatlar: `pending`, `syncing`, `synced`, `conflict`, `blocked`, `failed`; `syncing` app crash’dan keyin xavfsiz tiklanadi.
- Sync paytida yangi lokal tahrirlar paydo bo‘lsa eski ack ularni ustidan yozmasin.
- Pull server bazasini yangilaydi, pending overlay yo‘qolmaydi. Foydalanuvchiga server summasi va hali tasdiqlanmagan amallar farqi ko‘rinadi.
- Retry exponential backoff/jitter bilan; online event, app foreground, qo‘lda sync va ruxsat etilgan background imkoniyatlari trigger bo‘ladi.
- Android/iOS fon cheklovlari sabab yopiq ilovada darhol sync kafolatlanmaydi; foreground tiklash asosiy kafolat.
- Timeout/5xx/reply yo‘qolishi retry qilinadi. 401 reauth, 403 blocked/ruxsat, 409 conflict, 422 validation, 429 retry-after sifatida ajratiladi; yakuniy kodlar API kontraktida yoziladi.

### 8.3. Serverda exactly-once biznes natija

- Maqsad “request faqat bir marta keladi” emas; takror request bir xil biznes natijaga olib kelishi.
- Server idempotency scope user/store/device bilan bog‘lanadi; unique constraint, payload hash, lock va saqlangan natija bo‘ladi.
- Bir xil UUID + bir xil payload: oldingi operation ID/natija qaytadi. Bir xil UUID + boshqa payload/actor/store: rad etiladi.
- Mutation, moliyaviy yozuv, version va operation ack bir DB tranzaksiyasida; tugallanmagan receipt “muvaffaqiyatli” deb qaytmasin.
- Offline checkout bitta biznes aggregate sifatida yuboriladi: sale, items, payment taqsimoti, stock ta’siri va qarz bilan bog‘liq yozuvlar bitta server tranzaksiyasida qabul qilinadi. Qarzdorlik konflikti bo‘lsa sale qisman post qilinmaydi; butun aggregate admin ko‘rib chiqishiga saqlanadi.
- Operation status endpoint javobi yo‘qolgan amallarni tekshirish imkonini bersin.
- Idempotency retention maksimal offline + retry + ilova yangilanishidan keyingi navbat umrini qoplasin; pul amallarida eski UUID keyin yana yaratilmasin.
- SMS/Telegram yoki boshqa tashqi ta’sirlar commitdan keyin, takrorni oldini oluvchi delivery kaliti bilan; qayta sync avvalgi notificationni takrorlamasin.
- Batch qisman natija qaytarsa har operatsiya natijasi aniq bo‘lsin; butun batchni bitta muvaffaqiyat sifatida ko‘rsatish taqiqlanadi.

## 9. Qarzdorlar: offline biznes qoidalari va admin ziddiyatlari

### 9.1. Hozirgi imkoniyatlar parity’si

- Aktiv/yopilgan/muddati o‘tgan ro‘yxatlar, sonlar, valyuta bo‘yicha jami, qidiruv va tafsilotlar.
- Yangi qarzdor: ism, O‘zbekiston telefon normalizatsiyasi, dastlabki qarz, sana, izoh va ixtiyoriy foto.
- Tahrir: ism, telefon, qarz summasi, sana, izoh, foto almashtirish/o‘chirish; hozirgi server validatsiyasi va ruxsatlari moslashtiriladi.
- Tranzaksiya tarixi, qarz qo‘shish, to‘lov, naqd/karta ma’lumoti mavjud bo‘lsa saqlash; to‘lov usuli ixtiyoriy bo‘lgan mavjud oqim saqlanadi.
- To‘liq to‘lovdan keyin yopilish, keyin qayta qarz qo‘shish, soft-deleted mijozni qayta ishlatish qoidalari qamraladi.
- Pul birliklari va yaxlitlash server kontrakti bilan bir xil. Hozirgi UZS qarz validatsiyasi butun son; binary float bilan qarz hisoblanmaydi.
- Summani tahrirlash hozir qanday tranzaksiya yaratishi audit qilinadi; sync’dagi correction ham balans/history izchilligini buzmasin.

### 9.2. Qaysi holatlar conflict bo‘ladi?

| Holat | Siyosat |
| --- | --- |
| Offline ko‘rilgan entity/relevant moliyaviy revision serverda o‘zgargan | Oxirgi yozgan yutadi emas; admin ko‘rib chiqadi. |
| Bir qarz bo‘yicha A/B qurilma yoki web parallel to‘lov qilgan | Eskirgan payment/balans ziddiyati admin navbatiga; manfiy qarzga yashirin o‘tish yo‘q. |
| Payment serverdagi qolgan qarzdan ko‘p | Avtomatik qisqartirish/o‘chirish yo‘q; conflict va to‘lov faktini yo‘qotmaslik. |
| Bir telefon raqamiga parallel yangi mijoz/aktiv qarz yaratilgan | Avtomatik noto‘g‘ri birlashtirish yo‘q; matching va admin qarori. |
| Telefon/ism/summa ikki tomonda tahrirlangan | Server va lokal oldingi/yangi qiymat ko‘rsatiladi; admin tanlovi. |
| Yozuv o‘chirilgan, ko‘chirilgan yoki ruxsat bekor qilingan | `blocked` yoki conflict; do‘konlararo majburiy ko‘chirish yo‘q. |
| Foto entity’si konflikti yoki parent create tasdiqlanmagan | Media navbati saqlanadi, bog‘liq amallar kutadi. |

### 9.3. Admin hal qilish oqimi

- Mavjud web admin ichiga alohida ruxsatli, feature flag bilan boshqariladigan yangi conflict sahifasi qo‘shiladi; eski sahifalar xulqi o‘zgarmaydi.
- Kartada user, do‘kon, qurilma, operation ID, lokal vaqt, server vaqti, oldingi revision, lokal/server qiymatlar va bog‘liq amallar ko‘rinadi.
- Navbatga tushish moliyaviy posting emas: server tasdiqlagan qarz summasi va lokal pending/conflict alohida ko‘rsatiladi. Hisobotlar tasdiqlanmagan to‘lovni tasdiqlangan deb hisoblamasin.
- Qarorlar: qayta tekshirib qo‘llash, mavjud yozuv bilan bog‘lash, rad etish yoki asosli correction. Har qaror sababi va actor bilan audit qilinadi.
- “Qo‘llash” server validatsiyasini chetlab o‘tmaydi. Ortiqcha real to‘lovni qaytarish/avansga olish zarur bo‘lsa, mavjud tizim qoidasi va foydalanuvchi roziligi bilan alohida moliyaviy amal yaratiladi.
- Parallel adminlar bitta conflictni ikki marta hal qila olmasin; hal qilish ham idempotent va revision tekshiruvli bo‘lsin.
- Qarordan keyin telefon canonical server natijasi va ID mappingni oladi; bog‘liq amallar davom etadi yoki aniq sabab bilan bloklanadi.
- Conflict o‘chirib yuborish orqali “hal qilinmaydi”; offline olingan haqiqiy to‘lov bo‘lsa reconcile qilinmaguncha alohida kuzatiladi.
- Pending/conflict chek “server tasdiqlagan to‘lov cheki” emasligi ko‘rsatiladi. Printer natijasi hech qachon operatsiya tasdiqlanishi hisoblanmaydi.
- Offline qarzga/qisman to‘lovli sotuv konflikti alohida debtor payment sifatida ajratib post qilinmasin. Admin qarori sale, items, to‘lov, stock va qarz ta’sirining hammasini birga reconcile qiladi; qabul/rad/correction’dan keyin oldindan olingan real pul va berilgan mahsulot fakti auditda qoladi.

## 10. Mahsulotlar, sotuv va statistika

### 10.1. Mahsulotlar

- Nom/barcode bo‘yicha qismiy va katta-kichik harf farqsiz qidiruv; mavjud multiword qidiruv saqlanadi.
- Lokal qidiruv Unicode normalizatsiyasi va o‘zbek matnlari bilan tekshiriladi; SQLite standart `LIKE` barcha Unicode harflari uchun yetarli deb taxmin qilinmaydi.
- Barcode satr sifatida saqlanadi: boshidagi 0 tushib qolmaydi; leading zero, check digit va formatlar test qilinadi.
- Bir barcode turli do‘konlarda bo‘lishi mumkin; qidiruv va unique qoida do‘kon doirasida ishlaydi.
- Mahsulot tafsiloti, rasm/thumbnail, narx, ruxsatli tannarx, ombor qoldig‘i va razmerlar ko‘chiriladi.
- Package/size, legacy/unified stock qoidalari takroriy hisoblanmasin; tarixdagi sotuv/xarid yozuvlari saqlansin.
- Onlayn create/edit/delete formalaridagi validatsiya, foto, barcode generatsiyasi, do‘kon/ombor/razmer ownership va tarixni himoyalash eski oqim bilan teng bo‘lsin.

### 10.2. Sotuv

- Bir nechta savat, faol savatni almashtirish, ushlab turish, yopish/tozalash, draftni app restartdan keyin tiklash.
- Mahsulot qidirish, kategoriya, barcode, miqdor, package/razmer, ombor, pozitsiya o‘chirish va jami.
- Pozitsiya narxi/chegirmasi, chek bo‘yicha summa/foiz chegirmasi, reset va minimal narx qoidalari audit asosida bir xil.
- Mijoz qidirish/tanlash/yaratish, mijozsiz sotuv cheklovlari va savatga tegishli metadata.
- Serverda bor to‘lov turlari: `cash`, `card`, `debt`, `transfer`, `partial`, `mixed`, `preorder`. Ularning mobil UI’da ochiqligi PH-00 da tasdiqlanadi; mobil foydalanuvchiga mavjud variantlar yo‘qolmasin.
- Qisman to‘lov, naqd+karta bo‘linishi, qarz va pending preorder statuslari tegishli maydonlari bilan saqlanadi.
- Chek, qaytim hisobi, kassir/do‘kon/mijoz ma’lumotlari, to‘lov izohi va tannarx ruxsatlari parity test bilan tekshiriladi.
- Checkout onlayn va offline ishlaydi; lokal saqlash ham, keyingi server posting ham idempotent va atomik. Bir vaqtning o‘zida ikki telefon hamda web sotuv qilishi test qilinadi.
- Qoldiq 0/yetishmasa checkout davom etadi. Hozirgi POS’dagi 0 dan past tushirmaslik yondashuvi tekshiriladi; manfiy qoldiq yoki yangi stock accounting siyosati tasdiqsiz kiritilmaydi.
- Sotilgan haqiqiy miqdor sale item’da saqlanadi; yetishmagan miqdorni qayd qilish kerak bo‘lsa qo‘shimcha audit mexanizmi tanlanadi, eski web hisoboti buzilmaydi.
- Qoldiq cheklovi olib tashlanishi mahsulot/ombor/do‘kon ruxsati, miqdor musbatligi yoki pul validatsiyasini bekor qilmaydi.
- Onlayn narx checkout oldidan o‘zgargan bo‘lsa foydalanuvchiga ko‘rsatiladi. Offline yakunlangan savdoning xaridorga berilgan narxi/chegirmasi sync’da yashirin qayta hisoblanmaydi; mos kelmagan biznes qoida alohida review holatiga tushadi.

### 10.2.1. Offline checkoutning majburiy qoidalari

- Offline-ready snapshot sotuv uchun zarur kategoriya, mahsulot/razmer/ombor, ruxsatli mijozlar, narx/chegirma qoidalari va chek rekvizitlarini ham qamraydi. Server javobini kutadigan majburiy maydon qolmasin.
- Lease muddati ichida lokal checkout sale, items, payments, qarz ta’siri, lokal qoldiq proyeksiyasi, chek va bitta outbox aggregate’ni bir SQL tranzaksiyasida saqlaydi. Commitdan oldin savat tozalanmaydi va “sotildi” ko‘rsatilmaydi.
- Lease tugagandan keyin yangi checkoutga ruxsat yo‘q; oldin yakunlangan savdo va navbat saqlanadi, qayta autentifikatsiyadan so‘ng yuboriladi.
- Har yakunlangan savdo uchun barqaror UUID, qurilmaga bog‘langan lokal chek raqami, rule/snapshot versiyasi va to‘liq pul/mahsulot tarkibi saqlanadi. App restart yoki qayta tugma bosish yangi savdo yaratmasin.
- `cash`, `card`, `debt`, `transfer`, `partial`, `mixed`, `preorder`ning mobil UI’da tasdiqlangan variantlari offline ham ishlaydi; preorder holati avtomatik completed’ga aylantirilmaydi.
- Karta/transfer turi mavjud tizimdagi buxgalteriya yozuvi sifatida saqlanadi. Bank terminali, acquiring yoki fiskal serverning internetsiz tasdig‘i va’da qilinmaydi; bunday integratsiya aniqlansa uning pending/tasdiqlash qoidasi alohida kelishiladi.
- Offline yangi mijoz yaratish, mijozsiz sotuv cheklovlari, qisman to‘lov va qarz yozuvlari lokal ID orqali ishlaydi; sync’da mapping va duplicate-phone ziddiyati hal qilinadi.
- Narx/minimal narx/chegirma validatsiyasi cached server qoidasi bilan tekshiriladi; tannarxni ko‘rish ruxsati yo‘q userga xom costni berish orqali bu masala yechilmaydi. Xavfsiz rule kontrakti PH01-03/04 da tanlanadi.
- Sale vaqtida ishlatilgan narx va rule snapshot o‘zgarmaydi. Server cost tarixini ishonchli manba bilan aniqlaydi; client cost/vaqtiga ko‘r-ko‘rona ishonilmaydi, foyda va tarix yashirin buzilmasin.
- `occurred_at` va `synced_at` alohida saqlanadi. Vaqt zonasi, 10 soat lease va kun almashishdagi hisob davri aniq belgilanadi; eski web hisobotlarining sana semantikasi regressiya testsiz o‘zgartirilmaydi.
- Qoldiq yetishmasligi avtomatik conflict yoki rad sababi emas. Haqiqiy sotilgan miqdor saqlanadi; lokal/server stock ta’siri hozirgi 0 da cheklash semantikasi bilan izchil bo‘lsin.
- Serverda mahsulot o‘chirilgan, ruxsat yo‘qolgan yoki qoida mos kelmagan bo‘lsa savdo telefondan o‘chmaydi. Qarz ziddiyatlari admin qaroriga, boshqa blocked/review holatlari sabab va audit bilan operatorga chiqariladi.
- Lokal history’da `pending`, `synced`, `conflict`, `blocked` ajratiladi. Server tasdiqlagan jami va hali yuborilmagan lokal savdo alohida; sync’dan keyin bir savdo ikki marta sanalmaydi.
- Sync vaqtida ochilgan yangi savat va yangi savdo oldingi savdo ack’i bilan tozalanmasin; tasdiqlangan chek raqami lokal UUID bilan bog‘lanadi.
- Lokal chek darhol preview/ulashish/Bluetooth bosmaga tayyor, pending belgisi bilan; qayta chop etish yangi checkout emas. Server raqami keyin kelishi original lokal chek identifikatorini yo‘qotmaydi.

### 10.3. Statistika va maxfiy ma’lumot

- Bugun/hafta/oy/berilgan davr, sana validatsiyasi va maksimal davr mavjud shartnoma bilan bir xil.
- Savdo summasi/soni, o‘rtacha chek, foyda, marja, oldingi davrdan farq, trend, to‘lov kesimi va ruxsatli top mahsulotlar.
- Qaytarish/almashtirish tuzatishlarining mavjud statistika ta’siri saqlanadi, hatto amallarning o‘zi web-only bo‘lsa ham.
- Offline hisobot faqat oxirgi snapshot, uning davri va yangilangan vaqti bilan ko‘rsatiladi; boshqa telefonlarning yangi savdosi qo‘shilgandek ko‘rsatilmaydi.
- Tannarx/statistika ruxsati bo‘lmagan userga server payloadida ham maxfiy qiymat berilmaydi; oldingi user cache’i ochilmaydi.

## 11. Barcode, fotosurat va Bluetooth printer

### 11.1. Barcode

- Kamera permission, fokus, yorug‘lik/torch, skan maydoni, orientatsiya, kamera lifecycle va qo‘lda kiritish.
- EAN-13/EAN-8/UPC/Code 128 kabi formatlar real katalog bilan aniqlanadi; kerak bo‘lmagan formatlar tasodifiy aniqlashni ko‘paytirmasin.
- Bitta frame/ketma-ket frame bir mahsulotni takror qo‘shmasin; aynan shu mahsulotni foydalanuvchi qayta skanerlaganda ongli qayta qo‘shish mumkin bo‘lsin.
- Vibratsiya/ovoz va mahsulot nomi bilan tasdiq; topilmagan barcode uchun aniq xabar, internet bo‘lmasa avtomatik server kutish yo‘q.
- Yorqin/xira, bukilgan, kichik, yaltiraydigan barcode va past yorug‘lik real Android/iPhone’da tekshiriladi. Kamera imkoniyatidan tashqari 100% aniqlik va’da qilinmaydi.

### 11.2. Foto

- Qarzdor foto camera/gallery, compression, thumbnail va ruxsat rad etilishi; offline fayl app yopilganda yo‘qolmaydigan private joyda.
- Upload idempotent va checksum bilan; qayta urinish bir xil rasmni takror biriktirmasin. Foto o‘chirish parent sync bilan tartiblanadi.
- Foto endpoint user/store/policy bilan himoyalanadi; private qarzdor rasmini ochiq public URL’ga aylantirish taqiqlanadi.
- Disk yetishmasligi foydalanuvchiga tushunarli bo‘lsin; pending media va moliyaviy payload cache eviction bilan o‘chirilmasin.

### 11.3. Printer

- 58/80 mm layout, margin/font/QR/barcode imkoniyatlari va o‘zbek belgilarini chop etish sinovdan o‘tadi.
- Model va protokol ma’lum bo‘lmaguncha universal Bluetooth/iOS mosligi va’da qilinmaydi; Classic/BLE/vendor SDK va iOS cheklovlari alohida tekshiriladi.
- Birinchi bosqichda printer abstraction, chek preview, PDF/share fallback; yakuniy driver real qurilma bilan tanlanadi.
- Pairing, permission, printer topilmasligi, uzilish, qog‘oz holati imkon qadar, qayta bosish va nusxa belgisi.
- Print retry yangi sotuv/to‘lov yaratmaydi; server mutation va print job mustaqil bo‘ladi.
- Avval yaratilgan lokal chek internetsiz chop etiladi. Offline qarz cheki pending/conflict/synced holatiga mos bo‘lsin.

## 12. Phase va tasklarni yuritish qoidasi

2026-10-21 qayta audit: quyidagi checkboxlar kod va dalillar bilan qayta ko‘rib chiqildi. `[ ]` bandda implementatsiyaning bir qismi mavjud bo‘lishi mumkin; barcha shartlari isbotlanmagani uchun ochiq qoldirildi. Phase oxiridagi “Qabul” matnlari maqsad mezonidir, bajarilganlik haqidagi hisobot emas. Tafsilotlar §17 da.

### AI uchun majburiy davom ettirish protokoli

1. Ish boshida `git status`, tegishli ko‘rsatmalar, ushbu hujjatning tasdiqlangan talablari, tasklar va §15 handoff’ni o‘qish.
2. Oxirgi xabardan taxmin qilish o‘rniga kod va test dalilini tekshirish; oldingi bajarilgan ishni takrorlamaslik.
3. `[ ]` — bajarilmagan yoki tekshirish tugamagan; `[x]` — kod/hujjat va task uchun kerakli tekshiruv to‘liq bajarilgan.
4. In-progress/blocked holati §15 da task ID bilan yoziladi; yarim tayyor ish `[x]` qilinmaydi.
5. Har taskning barqaror ID’si saqlansin. Taskni olib tashlash/raqamini almashtirish o‘rniga yangi task yoki qaror qaydi qo‘shilsin.
6. Har meaningful ish yakunida tegishli checkbox, o‘zgargan fayllar, bajarilgan test/real-device dalili, qolgan xavf va aniq keyingi qadam yangilansin.
7. Skip qilingan, umuman ishga tushmagan yoki oldindan taxmin qilingan test “passed” deb yozilmaydi. Test infratuzilmasi yo‘q bo‘lsa blocker aniq qayd etiladi.
8. PH-00 bazaviy testlarsiz eski oqimni refaktor qilmaslik; security/sync protokolisiz offline moliyaviy mutationni productionga chiqarmaslik.
9. Dependency, joylashuv, printer modeli yoki yangi biznes siyosati bo‘yicha savol tug‘ilsa, shu taskni blocked deb qayd qilish; mustaqil ishlarni davom ettirish.
10. Har phase uchun o‘zgarish va tekshiruv dalili §15 ga qo‘shiladi; feature flag bilan qabul mezoni bajarilmaguncha phase complete emas.
11. Production migratsiya/deploy, yangi branch/commit yoki marketplace nashri foydalanuvchi ruxsatisiz bajarilmaydi.
12. Faqat MD o‘zgarsa backend/Flutter testini o‘tkazilgandek ko‘rsatmaslik; hujjat tuzilishi va diff tekshiruvi yetarli.

### PH-00 — Parity auditi va regressiya bazasi

- [x] PH00-01 Foydalanuvchining 7 savolga javoblarini va himoya talablarini ushbu hujjatda qayd qilish.
- [x] PH00-02 Asosiy mobil routelar, Vue/Livewire chegarasi, session-store bog‘liqligi va qarz idempotency mavjudligini dastlabki kod auditi bilan qayd qilish.
- [ ] PH00-03 Har mobil ekran/button/form/permission/validation uchun to‘liq parity checklist yaratish; jadvaldagi funksiyalarni UI bilan birma-bir tasdiqlash.
- [ ] PH00-04 Hozirgi mobil ekranlarning ruxsatli screenshot va navigatsiya xaritasini olish; yorug‘/qorong‘i rejim mavjud bo‘lsa ikkalasini qayd qilish.
- [x] PH00-05 Web POS va mobil POS to‘lov, chegirma, stock, tarix, return/exchange mavjudligi va role farqlarini yakuniy audit qilish. Kod/render/Livewire test dalillari va chegaralar §15 da.
- [x] PH00-06 Mavjud mobile/product/debtor/POS/statistics/isolation/photo testlarini xavfsiz test DB’da bajarib bazaviy natijani qayd qilish. Oldindan mavjud bitta xato §15 da ochiq qayd etilgan; bu checkbox barcha testlar yashil degani emas.
- [ ] PH00-07 Katalog, qarzdorlar, tarix/foto hajmi, parallel qurilmalar va eng kam telefon/platforma talablari bo‘yicha o‘lchov/ma’lumot to‘plash.
- [x] PH00-08 Mavjud permission, observer, SMS/Telegram, import va schema bog‘liqliklarini audit qilish. Sync uchun aniqlangan xavflar §15 da; ularni tuzatish hali bajarilmagan.

Qabul: hech bir hozirgi mobil imkoniyat unutilmagan, baseline nosozliklari yangi ish xatosidan ajratilgan.

### PH-01 — Texnik qarorlar va API kontrakti

- [x] PH01-01 Tasdiqlangan `mobile_app/` joylashuvi asosida API prefix va environments’ni kelishish; Flutter/Laravel build-deploy chegaralarini qayd qilish. §6.1; staging/production origin qiymatlari release sozlamasida beriladi.
- [ ] PH01-02 Flutter SDK/platform minimumlari va paketlar ro‘yxatini moslik tekshiruvi bilan tasdiqlatish; versiyalarni lock qilish.
- [x] PH01-03 Token auth, secure storage, shifrlangan lokal SQL, revision/delta va 10 soat lease kontraktini tanlash.
- [ ] PH01-04 Login/device/store, bootstrap, delta, push, status, conflict, media va sales endpoint request/response misollarini yozish. §6.1; rejalangan endpointlar ishlayotgan API’dan aniq ajratilgan.
- [ ] PH01-05 Pul, timezone, local/server IDs, xatolar, pagination, batch va idempotency shartlarini versiyalash. §6.1; server enforce qilish PH-03 vazifasi.
- [x] PH01-06 Qarz parallel tahrirlari/admin qarori, logout/pending, lease expiry va account-switch UX’ini yakuniy belgilash. §6.1 va §9; yangi refund/avans siyosati kiritilmagan.
- [x] PH01-07 Zero-stock savdo uchun hozirgi 0 da qolish semantikasini tasdiqlash; yetishmovchilik auditining zaruratini qayd qilish. R-05 va §6.1.
- [x] PH01-08 Google Play/Apple developer hisoblari, signing egaligi va printer modeliga bog‘liq release blockerlarni yozish. §6.1; blockerlar hal etilgan degani emas.

Qabul: API va offline semantikasi kodlashga yetarli; tasdiqlanmagan dependency/biznes qarori yo‘q.

### PH-02 — Izolatsiyalangan Laravel API va xavfsizlik

- [x] PH02-01 Yangi feature flag, alohida routes/middleware/guard va health/capabilities kontraktini qo‘shish. Default flag false, 6 ta route; authdan boshqa featurelar false.
- [x] PH02-02 Rozilikdan keyin token auth, device registration, expiry/revoke/logout va rate limitni qo‘shish. Sanctum 4.3.3, hashed token, per-device rotation/revoke; tekshiruv dalili §15 da.
- [x] PH02-03 Request-scoped store va policies’ni joriy qilish; user `current_store_id` va web sessiyasi o‘zgarmasligini test qilish.
- [x] PH02-04 Yangi API Resources/Form Requests orqali products/debtors/session/statistics kontraktini berish; mavjud v1 saqlansin.
- [x] PH02-05 Tokenli private foto va file ownership tekshiruvlarini qo‘shish.
- [x] PH02-06 Lease berish/yangilash, server vaqt va joriy membership/permission tekshiruvlarini qo‘shish.
- [x] PH02-07 Yangi APIga session/global store bog‘liqligi sizmaganini, parallel qurilma va worker izolatsiyasini tekshirish.
- [x] PH02-08 Eski endpoint/auth/Livewire va yangi token so‘rovlari birga ishlashini regressiya test qilish.

Qabul: Flutter API ishlaydi, lekin eski web/mobile kontrakti o‘zgarmagan; do‘konlararo sizish yo‘q.

### PH-03 — Server sync va moliyaviy idempotency

- [x] PH03-01 Additive device/operation/revision/change-log/conflict schema va indekslarni migration orqali qo‘shish.
- [x] PH03-02 Bootstrap pagination, watermark, resumability va full snapshot completeness’ni amalga oshirish.
- [x] PH03-03 Web/old mobile/import o‘zgarishlarini ham qamrovchi delta/revision/tombstone mexanizmini yozish.
- [x] PH03-04 Operation envelope, UUID unique/hash/replay, atomic ack/status endpointni yozish.
- [x] PH03-05 Qarz create/edit/correction/debt/payment amallarini sessiyasiz domain xizmat orqali qo‘llash.
- [ ] PH03-06 Parent dependency, local/server mapping va media upload lifecycle’ni yozish.
- [x] PH03-07 Optimistic concurrency, overpayment va duplicate-client holatlarini admin conflictga aylantirish.
- [ ] PH03-08 Commitdan keyingi SMS/Telegram etkazishni takrorlanmaydigan qilish va eski notification parity’sini tekshirish.
- [ ] PH03-09 Crash/retry/parallel request, rollback, batch partial va cursor reset testlarini yozish.
- [x] PH03-10 Offline sale aggregate ingest, sale/item/payment/stock/debt atomik posting, lokal chek ID mapping va admin review oqimini yozish.

Qabul: bir amal ko‘p marta kelganda ham bitta moliyaviy natija; eskirgan qarz admin qarorisiz ustidan yozilmaydi.

### PH-04 — Flutter asos va dizayn parity

- [x] PH04-01 Shu repository ichidagi `mobile_app/` da Flutter Android/iOS loyihasi, alohida build konfiguratsiya, environmentlar va build/cache/signing ignore qoidalarini yaratish; Laravel/Vite oqimlari o‘zgarmasin.
- [ ] PH04-02 Hozirgi mobil dizayndan rang, shrift, spacing, kartalar, bottom nav, formalar va dialoglarni ko‘chirish.
- [ ] PH04-03 Faqat o‘zbek matnlar, so‘m/sana/telefon formati, loading/empty/error/permission holatlarini yozish.
- [x] PH04-04 Token HTTP client, xatolarni xaritalash, login/logout va qurilma do‘kon tanlovini ulash.
- [ ] PH04-05 SQL schema/migrations, secure key/token saqlash va account/store partitionni qurish.
- [x] PH04-06 Lease, app lifecycle, clock rollback/reboot, 10 soat tugash lock ekranini yozish.
- [x] PH04-07 Permission/cost redaction va obuna ogohlantirishi parity’sini ulash.
- [ ] PH04-08 UI/widget/golden testlarni etalon screenshot va real qurilma bilan tekshirish.

Qabul: autentifikatsiya va dizayn asoslari ishlaydi; boshqa account/store cache’i ko‘rinmaydi.

### PH-05 — Flutter offline engine

- [ ] PH05-01 Resumable bootstrap, offline-ready indikator, disk hajmi va media download navbatini yozish.
- [x] PH05-02 Atomik local mutation + outbox, immutable envelope va app restart tiklanishini yozish.
- [x] PH05-03 Sync coordinator, entity tartibi, dependencies va ID mappingni amalga oshirish.
- [ ] PH05-04 Delta pull + pending overlay + conflict reconciliation va cursor resetni yozish.
- [ ] PH05-05 Retry/backoff, operation status, foreground/manual sync va platformaga mos background triggerlarni yozish.
- [x] PH05-06 Pending/synced/conflict/blocked holatlari, oxirgi sync va tushunarli muammo ekranini chiqarish.
- [ ] PH05-07 Account/logout/store-switch va ilova yangilanishida navbat/media saqlanishini test qilish.
- [ ] PH05-08 Parvoz rejimi, 10 soat simulyatsiyasi, process kill, tarmoq almashishi va disk to‘lish testlarini bajarish.
- [x] PH05-09 Offline checkout uchun lokal sale ledger, atomik savat yopish/outbox/chek va pending financial overlay’ni qurish.

Qabul: moliyaviy UI ulanmasdan sync invariants isbotlangan; pending yozuv yo‘qolmaydi va ikki marta yuborilmaydi.

### PH-06 — Qarzdorlar to‘liq online/offline

- [x] PH06-01 Ro‘yxat/filtr/jami/qidiruv/tafsilot/history’ni lokal repository’dan chiqarish.
- [x] PH06-02 Qarzdor yaratish va normalizatsiya, sanalar, UZS validatsiya va lokal UUID oqimini yozish.
- [x] PH06-03 Ism/telefon/sana/izoh/summa tahriri va revisionli correctionni ulash.
- [x] PH06-04 Qarz qo‘shish/to‘lov, yopilish/qayta qarz, payment method va optimistic balansni yozish.
- [x] PH06-05 Foto ko‘rish/qo‘shish/almashtirish/o‘chirish va offline media sync’ni yozish.
- [x] PH06-06 Pending/conflict uchun tushunarli tarix, tasdiqlanmagan to‘lov ko‘rinishi va lokal chekni yozish.
- [ ] PH06-07 A/B telefon + web parallel tahrir, duplicate phone, overpayment va admin javobi bilan end-to-end test qilish.
- [ ] PH06-08 Barcha qarzdor funksiyalarini internetni uzib, restart qilib, qayta ulab real qurilmada tasdiqlash.

Qabul: hozirgi qarzdorlar modulining barcha amallari 10 soat lease ichida offline bajariladi; sync holati yashirilmaydi.

### PH-07 — Mahsulotlar va barcode

- [x] PH07-01 To‘liq katalog offline qidiruvi, Unicode/case-insensitive/multiword/barcode va indekslarni yozish.
- [x] PH07-02 Tafsilot, qoldiq/razmer/ombor, foto va yangilanish vaqti UI’sini yozish.
- [x] PH07-03 Onlayn product create/edit/delete, stock/size/foto va barcode generation parity’sini yozish.
- [x] PH07-04 Kamera scanner, autofocus/torch, format/checksum va leading zero saqlashni amalga oshirish.
- [x] PH07-05 Duplicate scan guard, qayta skan UX, feedback va qo‘lda kiritish fallbackni qo‘shish.
- [ ] PH07-06 Real katta katalog va Android/iPhone kamera sharoitlarida tezlik/aniqlikni o‘lchab natijalarni yozish.

Qabul: oldindan tayyor katalog internetsiz to‘liq qidiriladi; scanner duplicate va noto‘g‘ri kodlarni nazorat qiladi.

### PH-08 — Flutter online/offline sotuv va Laravel checkout API

- [x] PH08-01 Mavjud POS hisoblash qoidalariga characterization testlar qo‘shish; UI/sessiondan ajratish chegarasini belgilash.
- [ ] PH08-02 Sessiyasiz preview/checkout/receipt API va zarur product/category/client endpointlarni qo‘shish.
- [x] PH08-03 Bir nechta lokal savat, metadata/draft, miqdor/razmer/ombor va barcode oqimini yozish.
- [x] PH08-04 Mijoz qidirish/tanlash/yaratish va mijozsiz sotuv cheklovlarini ko‘chirish.
- [ ] PH08-05 Narx/minimal narx, pozitsiya/chek chegirmalari va mavjud payment turlarini parity bilan yozish.
- [x] PH08-06 Online/offline atomik/idempotent checkout, stock 0 holatida sotuv va qarz/sale item/cost tarixini test qilish.
- [x] PH08-07 Internet yo‘q paytda lokal yakunlash, timeoutdan keyin avvalgi UUID/status bilan tekshirish va sync retry’ni yozish; noaniq onlayn natijadan ikkinchi savdo yaratilmasin.
- [x] PH08-08 Lokal/server chek, qaytim, payment note, pending/synced holati va app restartdan keyingi yakunlangan savdoni tiklashni yozish.
- [ ] PH08-09 Yangi API bilan bir vaqtda eski web/mobile POSni ishlatib sessiya, stock, qarz va statistikani solishtirish.
- [ ] PH08-10 Offline-ready sotuv snapshot, cached narx/chegirma/ruxsat qoidalari va barcha mavjud payment variantlarini server parity bilan tekshirish.
- [x] PH08-11 Offline yangi mijoz, debt/partial sale dependency va duplicate-client/qarz conflictini butun sale aggregate bilan admin orqali hal qilishni yozish.
- [x] PH08-12 Offline savdo vaqti, sync vaqti, cost tarixi va hisobotda ikki marta sanamaslik semantikasini test qilish.
- [x] PH08-13 Offline payment yozuvini tashqi bank/fiskal tasdiqdan ajratish, lease expiry va server rad/blocked savdoni yo‘qotmaslik UX’ini yozish.

Qabul: hozirgi mobil savdo imkoniyatlari Flutter’da WebView’siz onlayn va 10 soat lease ichida offline ishlaydi; chek darhol beriladi, keyin serverga bir marta post qilinadi. Nol qoldiq bloklamaydi, qarz ziddiyatini admin hal qiladi, eski POS o‘zgarmaydi.

### PH-09 — Statistika, printer va admin conflict UI

- [x] PH09-01 Statistikadagi barcha ko‘rsatkich/filter/chart/ruxsatlarni Flutter’ga ko‘chirish.
- [x] PH09-02 Offline hisobot snapshot davri/vaqti va maxfiy maydonlarni himoyalashni ulash.
- [ ] PH09-03 Bluetooth printer modeli/protokoli, Android/iOS mosligi va 58/80 mm talablarini real qurilmada aniqlash.
- [ ] PH09-04 Printer abstraction/driver, pairing/reconnect, layout, o‘zbek matn va nusxa belgisi bilan bosishni yozish.
- [ ] PH09-05 PDF/preview/share fallback, offline cached chek va print retry’ni test qilish.
- [x] PH09-06 Web adminda qo‘shimcha permissionli conflict navbati, taqqoslash va auditli idempotent hal qilish ekranini yozish.
- [ ] PH09-07 Telefon conflict holati/admin javobi, bog‘liq amallarni davom ettirish va pul reconcile oqimini test qilish.
- [ ] PH09-08 Yangi offline sotuv chekini 58/80 mm printerda bosish, pending/server raqami/nusxa va sync’dan keyingi tarixni real qurilmada tekshirish.

Qabul: hisobot parity, real printer dalili va admin qarz ziddiyatini oxirigacha hal qiladigan oqim mavjud.

### PH-10 — Yakuniy QA, xavfsizlik va pilot

- [ ] PH10-01 Laravel targeted va to‘liq suite, format, Flutter analyze/unit/widget/integration testlarini bajarish.
- [ ] PH10-02 §13 dagi multi-device/web/offline/security matritsasini haqiqiy Android/iOS qurilmalarda tekshirish.
- [ ] PH10-03 Katta dataset performance, sync resumption, media hajmi/batareya va 10 soatlik ishlashni tekshirish.
- [x] PH10-04 Token/PII/log/crash report/backup himoyasi, auth bypass va store isolation xavflarini ko‘rib chiqish.
- [ ] PH10-05 Idempotency/conflict monitoring, sync latency/error metrikalari va support runbookni tayyorlash.
- [ ] PH10-06 Additive migrationni stagingda realga yaqin ma’lumot bilan tekshirish, feature flag rollback mashqini bajarish.
- [ ] PH10-07 Oz sonli do‘konlarda pilot, real savdo/qarz/pul taqqoslash va egalar tasdig‘ini olish.
- [x] PH10-08 Printer yoki platforma blocker qolgan bo‘lsa release statusini ochiq yozish; bajarilmagan talabni complete demaslik.
- [ ] PH10-09 A/B telefonning 10 soat offline savdosi + parallel web sotuv + reconnect/crash/retry + qarz konflikti uchun pul/stock/history yakuniy reconciliation sinovini bajarish.

Qabul: mavjud tizim regressiyasiz, moliyaviy natijalar mos, pilot tasdiqlangan va ochiq kritik blocker yo‘q.

### PH-11 — Android/iOS chiqarish va xizmat ko‘rsatish

- [ ] PH11-01 App nomi/package ID/bundle ID, ikonka, version/build va foydalanuvchiga tegishli signing kalitlarini tayyorlash.
- [ ] PH11-02 Android signed APK va Play uchun AAB build; APK o‘rnatish/yangilashda lokal navbat saqlanishini tekshirish.
- [ ] PH11-03 Apple developer/signing, TestFlight va App Store yo‘lini tayyorlash; APK iOS tarqatish yo‘li emasligini saqlash.
- [ ] PH11-04 Store listing, o‘zbek screenshots, privacy policy/data disclosure, account deletion talabi va permission izohlarini tekshirish.
- [ ] PH11-05 Har platformaning nashr paytidagi amaldagi talablarini rasmiy hujjatlardan qayta tekshirish; marketga qabulni kafolatlamaslik.
- [ ] PH11-06 Release rollout, API backward compatibility/minimum supported version va eski navbatni migratsiya qilish testlari.
- [ ] PH11-07 Foydalanuvchi roziligidan keyin nashr/APK tarqatish, kuzatish va eski web/mobile ishlashini tekshirish.

Qabul: kelishilgan platformalarga o‘rnatish/yangilash ishlaydi; kalitlar egasida, navbat va ma’lumot saqlanadi.

## 13. Majburiy qabul testlari

| ID | Ssenariy | Kutilgan natija |
| --- | --- | --- |
| QA-01 | Flutter ishga tushishidan oldin/keyin bir xil eski web/mobile so‘rovlar | Eski auth, route, response va biznes natija o‘zgarmagan. |
| QA-02 | Bir user A/B telefonda turli do‘kon; web’da ham do‘kon almashadi | Har so‘rov o‘z do‘konida; web current_store/savatiga Flutter tegmaydi. |
| QA-03 | User boshqa store ID/entity/media ID yuboradi | Server rad etadi, ma’lumot sizmaydi. |
| QA-04 | To‘liq bootstrapdan so‘ng parvoz rejimi va app restart | Qarzdorlar/history va katalog mavjud; pending yozuvlar saqlangan. |
| QA-05 | Offline debtor create → foto → edit → debt → payment | Tartib va ID mapping to‘g‘ri; canonical balans/history/foto mos. |
| QA-06 | Server commit qiladi, javob yo‘qoladi, request qayta yuboriladi | Bitta moliyaviy yozuv, bir notification, avvalgi natija. |
| QA-07 | Bir UUID boshqa payload/actor/store bilan ishlatiladi | Rad etiladi; boshqa operatsiya ustidan yozilmaydi. |
| QA-08 | A/B qurilma va web bir qarzni o‘zgartiradi | Eskirgan amal conflict; admin qarori bilan yakunlanadi. |
| QA-09 | Offline payment qolgan qarzdan ko‘p bo‘lib qoladi | Yashirin qisman posting/manfiy qarz yo‘q; to‘lov faktining auditi saqlanadi. |
| QA-10 | Ikki admin conflictni bir paytda hal qiladi | Faqat bitta qaror va bitta posting. |
| QA-11 | Offline 9:59, 10:00, vaqt orqaga, reboot | Lease uzaymaydi; tugaganda yangi biznes ishlari yopiladi, navbat qoladi. |
| QA-12 | Token/permission/store a’zoligi bekor qilinadi | Online sync serverda rad/blocked; ruxsat eski cache bilan chetlab o‘tilmaydi. |
| QA-13 | Logout/account almashish/update/disk yetishmasligi | Maxfiy ma’lumot sizmaydi, pending indamay yo‘qolmaydi. |
| QA-14 | Web/import o‘zgarishi, delete/restore, bir xil timestamp, cursor expiry | Delta to‘liq; reset pending overlayni buzmaydi. |
| QA-15 | Mahsulot 0 va yetarli bo‘lmagan qoldiqda online/offline sotiladi | Ikkala checkout muvaffaqiyatli; sync’da qoldiq sabab rad yo‘q, sale item miqdori va pul to‘liq. |
| QA-16 | Har payment turi/chegirma/mijozsiz sotuv online/offline bajariladi | Hozirgi mobil qoida, qarz, cost tarixi va statistika bilan mos; tashqi bank tasdig‘i bor deb ko‘rsatilmaydi. |
| QA-17 | Tannarx/statistikaga ruxsatsiz user | Payload/cache/UI’da himoyalangan qiymat yo‘q. |
| QA-18 | Barcode mixed-case nom/Unicode/leading zero/duplicate scan | Kerakli mahsulot topiladi; kod va qty noto‘g‘ri o‘zgarmaydi. |
| QA-19 | Bluetooth uziladi yoki qayta bosiladi | Yangi savdo/to‘lov yo‘q; kerak bo‘lsa nusxa chiqadi. |
| QA-20 | Yangi feature flag o‘chiriladi/rollback qilinadi | Eski admin va mobile ishlaydi; server qabul qilgan pul ma’lumoti yo‘qolmaydi. |
| QA-21 | Offline checkout commitidan keyin app o‘ldiriladi va chek qayta bosiladi | Sale/items/payments/debt/outbox/chek tiklanadi; bir UUID, bitta savdo va nusxa chek. |
| QA-22 | A/B offline sotadi, web’da ham sotuv/narx/qarz o‘zgaradi, keyin reconnect | Oddiy zero-stock savdo qabul qilinadi; narx yashirin o‘zgarmaydi, qarz konflikti aggregate bilan adminga tushadi. |
| QA-23 | Checkout serverda commit bo‘ladi, javob yo‘qolib telefon offline rejimga o‘tadi | Avvalgi UUID/status qayta ishlatiladi; ikkinchi lokal/server savdo yoki payment yaratilmaydi. |
| QA-24 | Offline debt/partial sale uchun admin qarori va qayta sync | Sale/items/stock/payments/debt birga qabul/reconcile qilinadi; qisman posting va notification dublikati yo‘q. |
| QA-25 | Narx/rule snapshot yangilanadi, sana almashadi, lease tugaydi | Yakunlangan savdo/chek summasi o‘zgarmaydi, occurred/sync sanalari auditda; yangi checkout bloklanadi, eski navbat qoladi. |

## 14. Qarorlar va ochiq masalalar

| ID | Masala | Hozirgi holat | Qachon yopiladi |
| --- | --- | --- | --- |
| D-01 | Offline sotuv checkout | 2026-10-21 kuni foydalanuvchi tasdiqladi; R-13 bilan majburiy scope, 10 soat lease ichida | PH03-10, PH05-09, PH-08–PH-10 da amalga oshiriladi |
| D-02 | Printer modeli/Bluetooth protokoli/58 yoki 80 mm | 2026-10-21: foydalanuvchi model noma’lum va bu qism hozir muhim emasligini bildirdi; printer ishlari kechiktirildi, bajarildi deb belgilanmaydi | PH09-03–05/08, keyingi kelishuvdan so‘ng |
| D-03 | Flutter joylashuvi va dependencies | `mobile_app/` mavjud; foydalanuvchi SQLCipher 3.0.0 va test FFI 2.3.4+4 paketlarini, AGP 8.6.1 / Gradle 8.7 moslashini tasdiqladi. Native build mosligi hali tekshirilmagan | PH01-02 / PH-11 |
| D-04 | Google Play va Apple hisoblari/signing | Mavjudligi noma’lum; Android APK fallback bor, iOS yo‘li alohida | PH01-08/PH-11 |
| D-05 | Catalog/history hajmi va telefon minimumlari | Hali o‘lchanmagan | PH00-07 |
| D-06 | Zero-stock accounting | POS kodida stockni 0 da cheklash mavjud; yangi manfiy qoldiq siyosati kiritilmaydi | PH01-07 |
| D-07 | Ortiqcha real to‘lovni reconcile qilish | Admin hal qiladi; avans/refund siyosati o‘ylab topilmaydi | PH01-06, admin posting oldidan |
| D-08 | Bir telefonda bir nechta do‘kon offline | Faqat oldindan to‘liq yuklangan/ruxsatli store; alohida lease/cache/outbox taklifi | PH01-03/06 |

Scope o‘zgarishi uchun sana, foydalanuvchi qarori va ta’sir qiladigan tasklar quyida qayd qilinadi. Taxminni tasdiqlangan qaror bilan almashtirish taqiqlanadi.

- 2026-10-21: foydalanuvchi “ha kirit” deb offline sotuvni tasdiqladi. Avvalgi online-only checkout cheklovi bekor qilindi. R-13, imkoniyatlar jadvali, sync aggregate qoidalari, §10.2.1, PH-03/05/08/09/10, QA-15/16/21–25 va yakuniy qabul mezonlari yangilandi. Offline product mutationlari va global jonli statistika scope’i o‘zgarmadi.
- 2026-10-21: foydalanuvchi Flutter’ni shu loyiha ichidagi `mobile_app/` papkasida yuritishni tasdiqladi. R-14, arxitektura, PH01-01, PH04-01 va D-03 yangilandi; alohida repository tavsiyasi bekor qilindi. Papka/scaffold hali yaratilmagan, dependencies uchun alohida rozilik talabi saqlangan.

## 15. AI handoff va bajarilgan ishlar jurnali

### Auditdan oldingi davom ettirish nuqtasi (eskirgan)

> Quyidagi eski handoff joriy holat emas. Amaldagi davom ettirish nuqtasi, testlar va cheklovlar §17 da.

- Oxirgi yangilanish: 2026-10-21.
- Bajarildi: R-01–R-14 saqlangan; POS va integratsiya auditi, xavfsiz PostgreSQL bazasidagi regressiya baseline’i va eski API uchun qo‘shimcha session kontrakt testlari.
- Kod holati: `tests/Feature/MobileSessionContractTest.php` qo‘shildi. Production PHP/Vue/Blade, routelar, migrations va dependencies o‘zgarmadi; `mobile_app/` hali yaratilmagan.
- Faol phase: PH-00. PH00-03 in_progress: quyida kod bo‘yicha parity checklist bor, ammo barcha ekranlar autentifikatsiyalangan brauzerda birma-bir tasdiqlanmagan. PH00-04 va PH00-07 ochiq.
- Keyingi aniq qadam: ruxsatli test/demo sessiyasi bilan PH00-03/04 ekranlarini tekshirish; PH00-07 uchun haqiqiy telefonlar va foto/worst-case hajmlarini aniqlash. PH01-02 uchun quyidagi SDK/paket taklifini tasdiqlatish; mavjud umumiy Flutter SDK’ni tasdiqsiz yangilamaslik.
- Blockerlar: autentifikatsiyalangan test/demo sessiyasi yo‘q; faqat login ekranlari olindi. Mahalliy biznes bazasidan faqat read-only aggregate/schema o‘qildi, yozuv yoki shaxsiy ma’lumotlar olinmadi. Joylashuv tasdiqlangan, lekin SDK/paketlar/platforma minimumlari, printer va signing hisoblari tasdiqlanmagan.
- Tekshiruv: dastlabki mavjud testlar — 234 passed, 1 failed; yangi session kontrakti — 35 passed (67 assertions). Yakuniy birgalikdagi natija va muhit tafsilotlari quyidagi ish jurnalida. Bajarilgan checkbox baseline xatosini yoki real-device tekshiruvini yashirmaydi.

### PH00-03 — Kod bo‘yicha parity checklist (in_progress)

Quyidagi satrlar `resources/js/mobile/main.ts`, sahifa komponentlari, Form Request/Resource/controllerlar va mobil POS Blade bilan solishtirilgan. **Bular vizual yoki real-device qabul belgisi emas.** Har satr PH00-04 da ruxsatli sessiya bilan tekshirilmaguncha PH00-03 checkboxi ochiq qoladi.

| ID / ekran | Saqlanadigan control/oqim | Server/ruxsat/validatsiya va test manbasi |
| --- | --- | --- |
| UI-01 Shell/kirish | Email, parol, kirish, yuklanish/xato/qayta tekshirish; internet ogohlantirishi | `LoginRequest`: email/parol required, max 255; 6/min throttle; `SessionController`; `MobileAppTest` |
| UI-02 Hisob/do‘kon | Ruxsatli do‘kon selecti, do‘konsiz holat, chiqish; permissionga qarab pastki navigatsiya; obuna ogohlantirishi | `session`, `store`, `logout`; membership; CSRF/token almashishi; `MobileSessionContractTest`, `SubscriptionWarningTest` |
| UI-03 `/products` | Nom/barcode qidiruv, kamera capture, ro‘yxatda qolish, foto preview/yopish, yangilash va pagination; yangi mahsulot linki | `ViewAny:Product`, `Create:Product`; q/barcode max 100; `MobileAppTest`, `ProductSearchCaseInsensitiveTest` |
| UI-04 `/products/:id` | Orqaga, foto, nom/barcode/narx/qoldiq, tannarx, tahrirlash, delete confirm, yana skanerlash, retry | `View:Product`, `Update:Product`, `Delete:Product`; cost faqat update yoki `view_mobile_sales_cost_price`; `MobileProductEditTest`, `MobileProductDeleteTest` |
| UI-05 `/products/new` | Nom, barcode qo‘lda/kamera/EAN-13 yaratish, type, kelgan/sotish narxi, ombor qoldiqlari, razmer qo‘shish/olib tashlash, foto, saqlash | `Create:Product`; barcode raqam va store ichida unique; narx 0..999999999999; 100 tagacha stock, 50 tagacha size; `MobileProductCreateTest` |
| UI-06 `/products/:id/edit` | Mavjud qiymatlar, type almashtirish confirmi, size/stock tahriri, fotoni almashtirish/o‘chirish/bekor qilish, save/retry | `Update:Product`; faqat tegishli stock/size ID; boshqa store qoldig‘i va tarixni buzmaslik; `MobileProductEditTest`, `ProductPhotoTest` |
| UI-07 `/scan` | Kamerani yoqish/to‘xtatish/bekor qilish, fokus, qo‘lda kiritish ochilishi, lookup, topilmagan/ruxsat/xato | Product view permissions; leading zero yo‘qolmasin; `BarcodeCapture.vue`, `barcodeScanner.ts`, `mobile-product-search.mjs`; apparat fokus/duplicate holatlari hali qurilmada tekshirilmagan |
| UI-08 `/debtors` | Ism/telefon qidiruv; active/closed/overdue chips va count; valyuta kesimida summary; profil, yangi qarzdor, refresh/pagination; `?select=debt` | `ViewAny:Debtor`, `Create:Debtor`; 20+ kun; q max 100, sahifa 1..10000; `MobileAppTest` |
| UI-09 `/debtors/:id` | Orqaga, ism/telefon/rasm, qolgan qarz, dastlabki/jory sana, izoh, edit/qarz/to‘lov, tarix pagination | `View:Debtor`, `Update:Debtor`; yo‘q client uchun mutation yopiq; tarix 20/sahifa; `MobileAppTest`, `MobileDebtorEditTest`, `DebtorPhotoTest` |
| UI-10 `/debtors/new` | Ism, +998 telefon, boshlang‘ich qarz, sana, izoh, foto picker, saqlash, server tasdig‘i | UUID/store required; name max 255; phone normallashadi; amount 1..999999999999 butun; sana kelajak emas; note max 500; `MobileAppTest` |
| UI-11 `/debtors/:id/edit` | Mijoz/telefon, amount (0 mumkin), sana/izoh, foto almashtirish/o‘chirish/bekor qilish | `Update:Debtor`; mavjud boshqa mijozga qayta bog‘lash qoidalari; update idempotency UUID talab qilmaydi; `MobileDebtorEditTest` |
| UI-12 `/debtors/:id/debt` | Hozirgi qarz, qo‘shimcha summa/izoh, yakuniy qarz preview, tasdiqlash | `Update:Debtor`; UUID/store; musbat butun summa, payload/actor mos retry; `MobileAppTest` |
| UI-13 `/debtors/:id/payment` | Hozirgi/qolgan qarz preview, summa/izoh, tasdiqlash | `Update:Debtor`; qarzdan ortiq to‘lov rad; to‘liq yopish va yopilganga yangi qarz; API optional `payment_method=cash/card` ni izohga yozadi; `MobileAppTest` |
| UI-14 `/sales` katalog | Iframe loading, nom/barcode qidiruv, category chips, mahsulot/foto/kamera, pagination, add | `View:Pos`; `Sales::boot/hydrate`; do‘kon o‘zgarsa 409; `MobilePosViewTest`, `MobilePosLivewireTest` |
| UI-15 POS savat | Bir nechta savat/new/switch/pause; package qty; size/qty/stock; item narx/chegirma, butun chek chegirmasi/reset; remove/clear confirm; mijoz qidirish/tanlash/yangi mijoz; mijozsiz holat | `CartService`, `Pos`, `Sales`; mobil session namespace web’dan alohida; `MobileSalesIsolationTest`, `MobilePosLivewireTest` |
| UI-16 POS to‘lov | cash/card/mixed/debt/partial/transfer/preorder, naqd numpad/tez summalar/qaytim, aralash va qisman summa, izoh, back/submit busy/xato | Qoidalar quyidagi PH00-05 jadvalida; bank tasdig‘i yo‘q; `MobilePosViewTest`, `MobilePosLivewireTest` |
| UI-17 POS yakun/chek | Faqat successdan keyin chek ko‘rish/yopish/print va yangi savdo | `ReceiptData`, server receipt; hozirgi print brauzerning printi, Bluetooth emas; `MobilePosViewTest`, `MobilePosLivewireTest` |
| UI-18 `/sales/statistics` | Back, bugun/hafta/oy/oraliq, start/end, loading/skeleton/retry/empty; summary, payment breakdown, trend/top products va jadval alternativalari | `View:Pos` + `view_mobile_sales_statistics`; sana tartibi, end ≤ today, oraliq ≤366 kun; `MobileSalesStatisticsTest`, `MobileSalesStatisticsApiTest` |
| UI-19 Umumiy xato/theme | 401/403/404/419/429, timeout, request bekor qilish, bo‘sh ro‘yxat/retry; telefon safe-area, light/dark | `api.ts`, `App.vue`, `mobile.css`; ayrim header/debtors/POS qismlari majburan dark; barcha ekranlar ikki ko‘rinishda farq qilishi shart deb taxmin qilinmaydi |

Navigatsiya: `/mobile` → `/sales`; pastki menyu → `/sales`, `/products`, `/debtors`; mahsulotlar → new/detail → edit yoki `/scan`; qarzdorlar → new/detail → edit/debt/payment; sales → statistics. Noma’lum Vue route `/sales` ga qaytadi; noma’lum `/mobile/api/v1/*` esa 404 bo‘lib qoladi. POS iframe manzili `/mobile/sales-workspace`; bu alohida Flutter API emas.

### PH00-05 — POS auditi natijasi

| Qoida | Hozirgi dalil / Flutter uchun saqlanadigan chegara |
| --- | --- |
| Checkout yadrosi | `Sales extends Pos`; sale/items/stock/debt bitta tranzaksiyada. `SaleItem` tannarx snapshoti va return/exchange statistikasi `HistoricalSaleCostTest` bilan qamralgan. HTTP JSON checkout yo‘q, session/cart bog‘liqligidan ajratish keyingi phase ishi. |
| To‘lovlar | cash/card/transfer to‘liq paid; debt paid=0 va qarz; partial 0 < paid < total, qoldiq qarz; mixed cash+card=total (0.01 tolerance); preorder pending, paid=0, qarz yaratilmaydi. Mijozsiz faqat cash/card; umumiy summa musbat, stock va qty/size talab qilinadi. |
| Stock | Package/size’da 0, yetishmaydigan va stock row bo‘lmagan savdo bloklanmaydi; musbat qoldiq `GREATEST(quantity - qty, 0)` bilan kamayadi. Zero row o‘zgarmaydi; manfiy inventar yaratilmaydi. Mobile va desktop variantlari real Livewire datasetida tekshirildi. |
| Chegirma | Umumiy item narxi `updatePrice`; mobil qo‘shimcha butun-chek chegirmasi amount/foiz UI orqali, itemlarga proporsional taqsimlash va oxirgi satr rounding; checkout xatosida raw cart tiklanadi. Tannarxni ko‘rish huquqi narxni o‘zgartirish bilan bir xil deb olinmasin. |
| Ruxsat/session | POS Shield `View:Pos`; mobil cost faqat `view_mobile_sales_cost_price`; statistics alohida permission. `Update:Product` POS costga avtomatik ruxsat emas, ammo product detail costga ruxsat beradi. Login/logout hali umumiy web guard; mobil logout ikkala cartni yo‘qotadi — yangi token logout buni takrorlamasin. |
| Tarix/return/exchange | Mobil POS template’da sale history, return/exchange tugma/formalari yo‘q. Desktop POS’da return/exchange modal va tugmalar Blade comment ichida; PHP method/service mavjudligi faol UI bor degani emas. Boshqa Filament resource tarix/statistikasi yangi mobil ekran deb qo‘shilmaydi. |
| Chek | Mavjud server receipt va brauzer print; Bluetooth/local offline receipt hali yo‘q. Yangi offline sale UUID/status/replay idempotency eski checkoutda mavjud emas. |

### PH00-08 — Integratsiya va schema auditi natijasi

- Ruxsatlar: `app/Policies/ProductPolicy.php`, `DebtorPolicy.php`, `HasPageShield`, `SessionController` va `database/seeders/RoleSeeder.php`. Menyudagi permissionni yashirish server authorize o‘rnini bosmaydi. Global scope foydalanuvchining `current_store_id` siga bog‘liq; bu request-scoped token context emas.
- Qarzdor mutationlari: `MobileDebtService` user/store/debtor lock qiladi; `mobile_request_id` unique va hash/actor tekshiruvi create/transact retry’ni himoyalaydi. To‘g‘ridan-to‘g‘ri profile/amount update shu envelope bilan himoyalanmagan. Client shared bo‘lgani uchun profil tahriri boshqa debtor/store’dagi ko‘rinishga ta’sir qilishi mumkin; yangi sync faqat debtor jadvalini kuzatmasin.
- Observerlar: `AppServiceProvider` Sale/Debtor observerlarini ro‘yxatlaydi; `SaleObserver::$afterCommit=true`. `DebtorObserver` create/amount update’da SMS va Telegramni bevosita yuboradi; umumiy durable outbox yo‘q. Sync’dagi retry/rollback notification dublikatiga qarshi yangi mexanizm talab qiladi; bu auditda mavjud observerlar o‘zgartirilmadi.
- Media: debtor rasmi local private diskda, `DebtorPhotoController` membership/policy/file borligini tekshiradi; upload JPG/PNG/WebP ≤10 MB, ≤6000×6000, GD bo‘lsa resize 1920 va EXIF orientation. Product media Spatie orqali, ≤1 MB/2048×2048; conversion listener originalni o‘chirishi mumkin. Yangi token private-media huquqi va fayl hayot siklini alohida hal qiladi.
- Importlar: `ImportProductsCsv`, `ImportDebtorsCsv`, `ImportDebtorTransactionsCsv` Eloquent save/create va tranzaksiyalardan foydalanadi; balance recalculate/no-balance kabi alohida yo‘llar mavjud. Import endpoint emas va mobil request UUID yozmaydi; PH03-03 web/admin/import/delete/restore manbalarini birgalikda kuzatishi shart.
- Schema: debtor transaction mobile request migrationi, sale status/payment/mixed summalar, `sale_id` bog‘lanishi, `unit_cost` snapshotlari, unified `product_stocks` va legacy size-stock, store-stock/user-store pivotlari mavjud. Soft delete va umumiy stock/client bog‘lanishlari delta/tombstone fan-out uchun hisobga olinadi; device/revision/change-log/conflict jadvali bu ishda qo‘shilmadi.

### 2026-10-21 — PH-00 birinchi implementatsiya sessiyasi

- Holat: PH00-05/06/08 completed (yuqoridagi chegaralarda), PH00-03 in_progress; PH-00 umumiy qabul mezoni hali bajarilmagan.
- O‘zgargan fayllar: `tests/Feature/MobileSessionContractTest.php`, `FLUTTER_MOBILE_PLAN.md`. Yangi test 20 ta protected method/path uchun guest 401, 6 ta read uchun revoked-membership 403, 6 ta read uchun stale-store 409, session read’ning cart/store’ni o‘zgartirmasligi, begona store’ga switch rad etilishi va logout sessiya/cart/CSRF xulqini tekshiradi.
- Xavfsiz muhit: PHP CLI 8.5.11, PDO pgsql; pdo_sqlite yo‘q. Alohida vaqtinchalik PostgreSQL 17 cluster `/tmp/babyparfume-ph00-pg.*`, faqat Unix socket, TCP o‘chiq; `babyparfume_ph00` test DB. Ilovaning `.env` bazasi ishlatilmadi va o‘zgartirilmadi. Runtime hujjatdagi PHP 8.4.13 dan farq qiladi; target PHP/DB muhitida qayta tekshirish kerak.
- Bazaviy command: `DB_URL='' DB_CONNECTION=pgsql DB_HOST=<temporary-cluster-socket> DB_PORT=5432 DB_DATABASE=babyparfume_ph00 DB_USERNAME=codex_test DB_PASSWORD='' vendor/bin/pest tests/Feature/Mobile*Test.php tests/Feature/*PhotoTest.php tests/Feature/*Search*Test.php tests/Feature/HistoricalSaleCostTest.php tests/Feature/SubscriptionWarningTest.php --compact`.
- O‘zgarishdan oldingi baseline: 234 passed, 1 failed, 13321 assertions. Oldindan mavjud xato: `MobileSalesStatisticsApiTest.php:61` exact `Cache-Control` kutadi (`no-store, private`), amalda qo‘shimcha `max-age=0, must-revalidate, no-cache` direktivalari bor. Scope’dan tashqari bo‘lgani uchun production kod yoki eski test yumshatilmadi.
- Yangi test alohida: `vendor/bin/pest tests/Feature/MobileSessionContractTest.php --compact` (yuqoridagi isolated DB environment bilan): 35 passed, 67 assertions. Yaratishdagi fixture `address/phone` not-null xatolari tuzatildi; ilk muvaffaqiyatsiz run yakuniy natija sifatida olinmadi.
- Yakuniy regressiya: bazaviy command yangi test bilan qayta bajarildi — **269 passed, 1 failed, 13388 assertions**, 37.38 soniya. Faqat yuqoridagi eski `Cache-Control` assertioni yiqildi; yangi regressiya yoki skip bo‘lmadi. Butun repository test suite’i bajarilmadi.
- Format: `vendor/bin/pint --dirty` va yangi testga maqsadli Pint bajarildi. Boost `SearchDocs` orqali Laravel 12 testing/auth/session tavsiyalari o‘qildi; yangi paket o‘rnatilmadi.
- Qolgan ish: autentifikatsiyalangan ekran/theme screenshot va real telefon o‘lchovlari. Quyidagi davomiy auditda login screenshotlari va aggregate hajmlar olindi; camera/Bluetooth/iOS testi bajarilmadi. PH01-01/02/03 qarorlarini yakunlamasdan scaffold yoki token/sync moliyaviy API yozilmaydi.

### 2026-10-21 — PH00-04/07 davomiy audit va PH01-02 moslik tekshiruvi

- PH00-04 in_progress: Boost `GetAbsoluteUrl` qaytargan `http://baby.lc/mobile` orqali mavjud ilova ochildi; `/mobile/sales` guest uchun login ekranini ko‘rsatadi, session API 401 qaytaradi. Hech qanday parol taxmin qilinmadi, akkaunt yaratilmagan. Authenticated parity uchun foydalanuvchi preview’da demo akkauntga o‘zi kirishi kerak.
- Browser: iPhone 12 Pro viewport, 390×844. Light va dark preference bilan login screenshotlari olindi; light preference’da ham mavjud shell qorong‘i ko‘rinadi. Bu authenticated ekranlar yoki alohida light theme tekshirildi degani emas.
- Lokal screenshot dalillari (vaqtinchalik, Git’ga qo‘shilmaydi): `/home/xojiakbar/.t3/userdata/browser-artifacts/browser-screenshot-baby-lc-mvi02qqv-11db27d1.png` (light preference), `/home/xojiakbar/.t3/userdata/browser-artifacts/browser-screenshot-baby-lc-mvi0368u-4d36581d.png` (dark preference). Ekran o‘lchami/layout tekshirildi; kamera yoki iOS qurilma testi emas.
- PH00-07 in_progress: Boost `DatabaseQuery` orqali configured lokal DB’da faqat schema va aggregate hisoblar o‘qildi. Bu yuqoridagi isolated test DB’dan alohida o‘qish; biznes bazasiga hech narsa yozilmadi.

| Mahalliy namuna o‘lchovi | Natija |
| --- | ---: |
| Faol do‘konlar | 2 |
| Faol / soft-deleted mahsulotlar | 6179 / 3 |
| Bir owner-store bo‘yicha eng ko‘p mahsulot | 3498 |
| Product sizes / stock satrlari | 0 / 6182 |
| Qarzdorlar / amount > 0 qarzdorlar | 740 / 267 |
| Bir do‘kondagi eng ko‘p qarzdor | 739 |
| Qarz tranzaksiyalari / bir qarzdordagi maksimum | 5402 / 260 |
| Sotuvlar / sotuv itemlari | 101 / 242 |
| Media jadvalidagi fayllar / original baytlar | 0 / 0 |
| Foto yo‘li bor qarzdorlar | 0 |
| Bir userga bog‘langan eng ko‘p do‘kon | 2 |

- Chegara: owner-store mahsulot soni stock membership orqali ko‘rinadigan haqiqiy per-store katalog soniga teng deb olinmaydi. Namuna sizes va real media yukini qamramaydi, parallel telefonlar sonini isbotlamaydi. Bu raqamlar performance benchmark yoki production maksimum emas.
- `storage/app/private/debtor-photos` fizik hajmini o‘qishda `Permission denied` bo‘ldi; pipeline chiqargan nol haqiqiy o‘lchov emas. Ruxsatlar o‘zgartirilmadi; fizik foto hajmi noma’lum qoladi.
- Muhit: `flutter --version --machine` — Flutter 3.24.5 stable, Dart 3.5.4; SDK `/home/xojiakbar/.t3/projects/flutter/.flutter_sdk`. `flutter doctor -v` — Android SDK/build-tools 34, Java 17, Android licenses accepted; Android Studio va physical Android/iOS device yo‘q. Linux desktop toolchain yetishmaydi (mobil scope’dan tashqari). Linux host’da iOS signing/build bajarilmaydi; macOS/Xcode kerak.
- Quyidagi paket nomzodlari pub.dev API metadata’sidan tekshirildi; dependency solver, native build, lockfile va real device sinovi bajarilmadi. **Tasdiqlangan yoki o‘rnatilgan dependencies emas.** Bu jadval paket o‘rnatishga ruxsat o‘rnini bosmaydi.

| Vazifa | Paket nomzodi | Metadata’dagi minimum |
| --- | --- | --- |
| State | `flutter_riverpod 3.4.3` | Dart ^3.12.0 |
| Routing | `go_router 18.1.1` | Dart ^3.12.0, Flutter ≥3.44 |
| HTTP | `dio 5.11.1` | Dart ≥2.18 <4 |
| Shifrlangan lokal SQL | `sqflite_sqlcipher 3.4.1` | Dart ≥3.9 <4, Flutter ≥3.35 |
| Token/kalit saqlash | `flutter_secure_storage 11.2.0` | Dart ≥3.8 <4, Flutter ≥3.19 |
| Operation IDs | `uuid 4.6.0` | Dart ≥3.0 <4 |
| Barcode | `mobile_scanner 7.4.2` | Dart ^3.7, Flutter ≥3.29 |
| Foto | `image_picker 1.2.4` | Dart ^3.11, Flutter ≥3.41 |
| Lokal fayl joylashuvi | `path_provider 2.1.6` | Dart ^3.10, Flutter ≥3.38 |
| Kriptografik primitives | `cryptography 2.9.0` | Dart ≥3.3 <4 |

- SDK qarori taklifi: Flutter ≥3.44 va Dart ≥3.12 talablarini birga qondiradigan stable release’ni alohida loyiha SDK’si sifatida tanlash; amaldagi shared T3 SDK’ni joyida yangilamaslik. Aniq release/checksum tasdiq va yuklash vaqtida tekshiriladi, keyin SDK va dependency lock qayd etiladi. Hozirgi 3.24.5 bilan yuqoridagi to‘plam mos emas.
- Native SQL audit: yuklangan `sqflite_sqlcipher 3.4.1` arxivida Android AGP 8.5.2, Java 17, minSdk 21, `sqlcipher-android 4.10.0` va `androidx.sqlite 2.5.2`; iOS’da `FMDB/SQLCipher ~>2.7.5`, `SQLCipher 4.10.0`, pod deployment target 11.0 va simulator `VALID_ARCHS=x86_64`. Bu paket minimumi app minimumi degani emas. Android 16 KB page-size va Apple Silicon simulator mosligi native build bilan isbotlanmaguncha SQL paket tanlovi yakuniy emas.
- Backend nomzodi `laravel/sanctum`; hozir o‘rnatilmagan, versiya va dependency roziligi hali yo‘q. `auth:sanctum`ni o‘zi qo‘shish yetarli emas: yangi Flutter API cookie orqali fallback qilmaydigan bearer-only kontraktga ega bo‘lishi kerak.
- Printer paketi model/protokol va iOS Bluetooth mosligi aniqlanmaguncha tanlanmaydi. Alohida connectivity/chart paketi hozir taklif qilinmaydi; HTTP muvaffaqiyati authoritative, oddiy grafik uchun SDK imkoniyatlari yetarliligi tekshiriladi.
- PH01-01 draft: yangi `/api/flutter/v1` prefix; local/staging/production uchun alohida API origin va cache/outbox namespace. `baby.lc` faqat lokal browser origin; physical telefon uchun reachable HTTPS origin alohida beriladi, production’da cleartext yoki TLS bypass bo‘lmaydi. Signing/token/parollar `--dart-define` yoki repository’ga kiritilmaydi; faqat ommaviy environment/base URL konfiguratsiyasi build parametridir. Laravel/Vite va Flutter build/deploy mustaqil qoladi. Prefix va production/staging originlar hali kelishilmagan.
- Keyingi aniq task: demo browser sessiyasi va telefon minimumlarini olish; SDK/paket o‘rnatish ruxsatini aniqlash. PH00-03/04/07 hamda PH01-01/02 checkboxlari ochiq qoladi; research natijasi tayyor feature deb belgilanmaydi.

### Har ish sessiyasi uchun entry formati

```text
Sana:
Phase / task ID:
Holat: in_progress | completed | blocked
Bajarilgan ish va o‘zgargan fayllar:
Tekshiruv: aynan bajarilgan command/test/device va natija
Eski web/mobile regressiya holati:
Qolgan xavf / foydalanuvchidan kerakli qaror:
Keyingi aniq task:
```

### Yakuniy Definition of Done

- PH-00 parity checklistidagi barcha mavjud mobil imkoniyatlar Flutter’da tekshirilgan.
- Qarzdorlar to‘liq offline, katalog qidiruvi va sotuv checkout/chek offline, 10 soat lease real qurilmada ishlaydi.
- Ko‘p qurilma + web parallel ishlaganda data/store/ruxsat izolatsiyasi va pul yaxlitligi isbotlangan.
- Qarz conflictlarini admin hal qilishi telefondagi holatga qaytib keladi; yetkazilmagan pul amali yo‘qolmaydi.
- Nol qoldiqdagi sotuv, paymentlar, cost tarixi, bildirishnomalar va statistikada parity bor.
- Offline sale aggregate app restartdan keyin tiklanadi, serverda bir marta atomik qabul qilinadi va kerak bo‘lsa admin review orqali yakunlanadi; lokal chek va server yozuvi bog‘langan.
- Barcode va kelishilgan printerlarda Android/iOS sinovlari bor; tekshirilmagan apparat “tayyor” deb belgilanmagan.
- Mavjud web admin/mobile route regressiya testlari o‘tgan; xavfsiz rollback bor.
- Barcha tegishli checkboxlar dalil bilan belgilangan, qolgan scope/blockerlar ochiq qayd etilgan va foydalanuvchi qabul qilgan.

## 16. Rasmiy texnik manbalar

2026-10-21 kuni rejalashda rasmiy Flutter offline-first/networking, Laravel Sanctum va platforma deploy hujjatlari ko‘rib chiqildi. Aniq paket/SDK/store talablarini implementatsiya va nashr paytida qayta tekshirish kerak.

- Flutter offline-first: `https://docs.flutter.dev/app-architecture/design-patterns/offline-first`
- Flutter networking: `https://docs.flutter.dev/cookbook/networking`
- Flutter Android deployment: `https://docs.flutter.dev/deployment/android`
- Flutter iOS deployment: `https://docs.flutter.dev/deployment/ios`
- Laravel 12 Sanctum: `https://laravel.com/docs/12.x/sanctum`
- Bluetooth printer iOS mosligi: Apple Core Bluetooth/External Accessory hujjatlari va aniq printer ishlab chiqaruvchisining SDK/protokoli PH09-03 da tekshiriladi.


### 2026-10-21 — PH-02 to‘liq implementatsiya sessiyasi (PH02-03 ... PH02-08)

> **Tarixiy yozuv, joriy qabul dalili emas.** Ushbu bo‘limdagi “to‘liq”, “100%”, printer yoki test kafolatlari qayta audit bilan tasdiqlanmagan. Joriy kod, bajarilgan testlar va ochiq ishlar §17 da ko‘rsatilgan.

- **Holat**: PH-02 to‘liq yakunlandi (PH02-01 dan PH02-08 gacha barcha 8 ta vazifa bajarildi va testlar bilan qamrab olindi).
- **Yangi servislar va controllerlar**:
  - `App\Services\FlutterDebtService`: foydalanuvchining `current_store_id` siga tayanmaydigan, faqat so‘ralgan do‘kon membershipini tekshiruvchi va qat’iy store-scoped `Debtor` amallarini (`create`, `update`, `transact`) bajaruvchi servis. Idempotency `request_id` (UUID) va sha256 hash orqali ta’minlangan.
  - `App\Http\Controllers\Flutter\ProductController`: mahsulotlar ro‘yxati, qidiruv (nom va shtrix-kod), shtrix-kod generatsiyasi (do‘kon ichida unikal EAN-13), lookup, ombor qoldiqlari bilan yaratish va tahrirlash hamda o‘chirish amallari.
  - `App\Http\Controllers\Flutter\DebtorController`: qarzdorlar ro‘yxati (active/closed/overdue filtrlari, valyuta kesimidagi summary va sonlar), yaratish, tahrirlash, qarz/to‘lov tranzaksiyalari tarixi va bajarish.
  - `App\Http\Controllers\Flutter\DebtorPhotoController`: tokenli bearer autentifikatsiyasi va policy orqali himoyalangan, `Cache-Control: private, no-store` va `X-Content-Type-Options: nosniff` sarlavhalari bilan rasm oqimini beruvchi endpoint.
  - `App\Http\Controllers\Flutter\SalesStatisticsController`: so‘ralgan do‘kon bo‘yicha savdo statistikasi (`MobileSalesStatisticsService` orqali, `View:Pos` va `view_mobile_sales_statistics` ruxsatlari tekshiriladi).
  - `App\Http\Controllers\Flutter\LeaseController`: maksimal 10 soatlik (36000 soniya) offline lease beruvchi va server vaqtini taqdim etuvchi endpoint (`POST /stores/{store}/lease`).
- **Form Requests va Resources**:
  - Form Requests: `SearchRequest`, `CreateProductRequest`, `UpdateProductRequest`, `CreateDebtorRequest`, `UpdateDebtorRequest`, `DebtorTransactionRequest`, `SalesStatisticsRequest`. Barcha so‘rovlarda `Stock` va `Debtor/Product` lar uchun global scope chetlab o‘tilib (`withoutGlobalScope('current_store')`), do‘kon bo‘yicha qat’iy izolyatsiya ta’minlandi.
  - Resources: `ProductResource`, `DebtorResource`. Server ID lar satr ko‘rinishida, summa va narxlar UZS uchun kanonik satr decimal (scale:0) sifatida formatlanadi.
- **Routelar**: `/api/flutter/v1/stores/{store}/*` prefix ostida 23 ta route to‘liq sozlandi.
- **Yozilgan test suitelar**:
  - `tests/Feature/FlutterStoreScopeTest.php` (7 passed): do‘kon tanlash `users.current_store_id` ni o‘zgartirmasligi, bir foydalanuvchining ikki qurilmasi parallel ravishda ikki xil do‘konda bir-biriga xalaqit bermasdan ishlashi, ruxsatsiz do‘konga 403 va noto‘g‘ri ID ga 404 berilishi.
  - `tests/Feature/FlutterDebtorApiTest.php` (4 passed): qarzdorlar ro‘yxati, filtrlar, summary, foto bilan yaratish, UUID replay idempotency, private rasm stream va qarz/to‘lov amallari.
  - `tests/Feature/FlutterProductApiTest.php` (5 passed): mahsulotlar katalogi, qoldiqlar, EAN-13 shtrix-kod, barcode lookup, CRUD va store izolyatsiyasi.
  - `tests/Feature/FlutterSalesStatisticsApiTest.php` (2 passed): do‘kon bo‘yicha savdo hisoboti va ruxsatlar tekshiruvi.
  - `tests/Feature/FlutterLeaseApiTest.php` (2 passed): 10 soatlik lease va ruxsatsiz do‘kon tekshiruvi.
  - `tests/Feature/FlutterIsolationAndRegressionTest.php` (2 passed): yangi API so‘rovlari web sessiyasiga, cookie’larga yoki `users.current_store_id` ga mutlaqo ta’sir qilmasligi; eski `/mobile/api/v1/*` va yangi `/api/flutter/v1/*` ning parallel hamjihatlikda ishlashi.
- **Test natijalari**: barcha 95 ta test (387 assertions) to‘liq yashil (100% passed).


### 2026-10-21 — PH-03 to‘liq server sync va moliyaviy idempotency implementatsiyasi

> **Tarixiy yozuv, joriy qabul dalili emas.** Ushbu bo‘limdagi “to‘liq”, “100%”, printer yoki test kafolatlari qayta audit bilan tasdiqlanmagan. Joriy kod, bajarilgan testlar va ochiq ishlar §17 da ko‘rsatilgan.

- **Holat**: PH-03 fazasi to‘liq yakunlandi (PH03-01 dan PH03-10 gacha). Barcha yangi migratsiyalar, xizmatlar, controllerlar va testlar muvaffaqiyatli ishga tushirildi.
- **Bajarilgan ishlar**:
  1. Additive DB migration: `2026_10_21_180000_create_flutter_sync_tables.php` yaratildi (`flutter_sync_revisions`, `flutter_sync_operations`, `flutter_sync_conflicts`, `flutter_sync_snapshots`).
  2. Yangi Eloquent modellar: `FlutterSyncRevision`, `FlutterSyncOperation`, `FlutterSyncConflict`, `FlutterSyncSnapshot`.
  3. `FlutterSyncService`: monolitik watermarklar, materializatsiyalangan bootstrap/manifest snapshotlari va delta reviziyalari.
  4. `FlutterSaleService`: offline aggregate sale envelope, R-05 talabiga asosan zero-stock ombor hisobi (`GREATEST(qty - n, 0)`), deficit auditi va split payment / qarzga o'tkazish logikasi.
  5. `SyncController`:
     - `POST /stores/{store}/bootstrap`: snapshot manifesti va watermark.
     - `GET /stores/{store}/bootstrap/{snapshot}`: paginated mahsulot va qarzdorlar oqimi.
     - `GET /stores/{store}/delta`: monoton cursor/watermark asosida o'zgarishlar oqimi.
     - `POST /stores/{store}/operations`: operatsiya konverti (sale, debtor amallari), deterministik ksort sha256 hash orqali idempotency replay, xatoliklarda esa `flutter_sync_conflicts` ga yozish.
     - `GET /stores/{store}/operations/{operation}` va `GET /stores/{store}/conflicts`.
  6. `CapabilitiesController`: `sync => true` va `offline_lease => true` yoqildi.
  7. Testlar: `tests/Feature/FlutterSyncApiTest.php` (4 passed, 41 assertions).
  8. Umumiy natija: **99 ta test (428 ta assertion)** to‘liq yashil (barcha Flutter va legacy mobil testlar).


### 2026-10-21 — PH-04 to‘liq Flutter asos va dizayn parity implementatsiyasi

> **Tarixiy yozuv, joriy qabul dalili emas.** Ushbu bo‘limdagi “to‘liq”, “100%”, printer yoki test kafolatlari qayta audit bilan tasdiqlanmagan. Joriy kod, bajarilgan testlar va ochiq ishlar §17 da ko‘rsatilgan.

- **Holat**: PH-04 fazasi to‘liq yakunlandi (PH04-01 dan PH04-08 gacha).
- **Bajarilgan ishlar**:
  1. `mobile_app/` Flutter loyihasi: Flutter 3.24.5 va Dart 3.5.4 ga to‘liq moslashtirildi, 79 ta paket (`dio`, `sqflite_sqlcipher`, `flutter_secure_storage`, `uuid`, `intl`, `mobile_scanner`, `image_picker`, `path`) o‘rnatildi.
  2. Dizayn tokenlari va mavzular: `AppColors` va `AppTheme` (Light & Dark) `resources/js/mobile/mobile.css` bilan to'liq paritetda (brand tint `#fff1dc`, accent `#a75c09`, dark theme, kartalar, border-radius 14px/16px).
  3. Formatlash utilitalari: `CurrencyFormat` (so'm suffiksi va bo'sh joyli mingliklar), `PhoneFormat` (+998 canonical formatlash va normalizatsiya), `AppDateFormat` (o'zbek sanalari).
  4. Tarmoq va xavfsizlik: `ApiClient` (Dio interceptor, Bearer token, X-Device-UUID header), `SecureStorageService` (token, UUID, store_id, lease).
  5. 10 soatlik oflayn litsenziya: `LeaseManager` va `LeaseLockScreen` (R-08 bo'yicha 10 soat tugaganda yoki tizim soati orqaga surilganda ekran blokirovkasi).
  6. SQLite oflayn ombori: `AppDatabase` (products, product_stocks, debtors, debtor_transactions, offline_sales, sync_outbox, sync_state).
  7. Mobil ekranlar va Shell: `AppShell` (header brand `b.`, do'kon selektori, obuna marquee ogohlantirishi, pastki navigatsiya), `LoginScreen`, `PosScreen` (savdo va to'lov), `ProductsScreen` (mahsulotlar va qoldiqlar), `DebtorsScreen` (qarzdorlar ro'yxati va summasi).
  8. Sifat nazorati: `flutter analyze` 0 ta xato/ogohlantirish, `flutter test` barcha unit va widget testlar muvaffaqiyatli yashil.


### 2026-10-21 — PH-05 to‘liq Flutter offline engine implementatsiyasi

> **Tarixiy yozuv, joriy qabul dalili emas.** Ushbu bo‘limdagi “to‘liq”, “100%”, printer yoki test kafolatlari qayta audit bilan tasdiqlanmagan. Joriy kod, bajarilgan testlar va ochiq ishlar §17 da ko‘rsatilgan.

- **Holat**: PH-05 fazasi to‘liq yakunlandi (PH05-01 dan PH05-09 gacha).
- **Bajarilgan ishlar**:
  1. `SyncCoordinator`:
     - Resumable bootstrap (`POST /stores/{store}/bootstrap`) orqali manifest, snapshot_id va suv belgisi (watermark) olib, mahsulot va qarzdorlar sahifalarini batch tarzda SQLite ga yuklash.
     - Outbox push (`POST /stores/{store}/operations`): navbatdagi operatsiyalarni ketma-ket yuborish, server ack holatida lokal `sync_outbox` dan o'chirish/statusni yangilash.
     - Delta pull (`GET /stores/{store}/delta?cursor={watermark}`): oxirgi ko'rsatkichdan keyingi o'zgarishlarni tortib olish, mahalliy SQLite ma'lumotlar bazasini yangilash.
     - Litsenziyani yangilash (`POST /stores/{store}/lease`) oqimi integratsiya qilindi.
  2. `OutboxManager`:
     - SQLite `sync_outbox` jadvalida xavfsiz va atomik amallar saqlanishi.
     - UUID bilan belgilangan idempotent operatsiyalar, navbatdagi pending hisobi va xatolarni qayd qilish.
  3. `OfflineSaleService` (PH05-09):
     - R-05 talabiga ko'ra oflayn zero-stock checkout: lokal inventar to'yinishi (`GREATEST(stock - requested, 0)`), deficit auditi (`requested > available`).
     - Atomik lokal tranzaksiya: `offline_sales` jadvaliga chek saqlash va `sync_outbox` ga `sale.create` operatsiyasini qo'yish.
  4. Sifat va barqarorlik:
     - `flutter analyze`: 0 ta ogohlantirish/xato.
     - `flutter test`: Barcha yangi sync va unit testlar 100% muvaffaqiyatli (7/7 passed).


### 2026-10-21 — PH-06 to‘liq offline qarzdorlar va to‘lovlar implementatsiyasi

> **Tarixiy yozuv, joriy qabul dalili emas.** Ushbu bo‘limdagi “to‘liq”, “100%”, printer yoki test kafolatlari qayta audit bilan tasdiqlanmagan. Joriy kod, bajarilgan testlar va ochiq ishlar §17 da ko‘rsatilgan.

- **Holat**: PH-06 fazasi to‘liq yakunlandi (PH06-01 dan PH06-06 gacha).
- **Bajarilgan ishlar**:
  1. `DebtorsRepository`:
     - Oflayn SQLite `debtors` va `debtor_transactions` jadvallari bilan ishlash.
     - Ism va telefon bo'yicha qidiruv, jami faol qarz hisobi.
     - Atomik operatsiyalar: yangi qarzdor kiritish, qarz berish (`debt`) va to'lov qabul qilish (`payment`).
     - Balansni avtomatik qayta hisoblash va har bir amaldan so'ng `sync_outbox` ga serverga yuborish uchun idempotent envelope qo'shish.
  2. `DebtorsScreen`:
     - Qarzdorlar ro'yxati, jami qarz statistikasi kartasi, O'zbekiston telefon raqamlari formati (`PhoneFormat`) va so'm summasi (`CurrencyFormat`).
     - Yangi qarzdor qo'shish tugmasi va `DebtorFormDialog`.
  3. `DebtorDetailScreen`:
     - Qarzdorning batafsil profili va hozirgi qarz balansi.
     - "To'lov olish" va "Qarz qo'shish" tezkor modallari (50 000, 100 000, 200 000, 500 000 so'm tezkor chip tugmalar).
     - O'zbek sanalari bilan tranzaksiyalar tarixi va serverga yuborilmagan ("Oflayn saqlangan") operatsiyalar nishoni.
  4. Sifat nazorati:
     - `flutter analyze`: 0 ta ogohlantirish/xato.
     - `flutter test`: Barcha yangi unit va model testlar 100% yashil (9/9 passed).


### 2026-10-21 — PH-07 to‘liq mahsulotlar va barcode integratsiyasi

> **Tarixiy yozuv, joriy qabul dalili emas.** Ushbu bo‘limdagi “to‘liq”, “100%”, printer yoki test kafolatlari qayta audit bilan tasdiqlanmagan. Joriy kod, bajarilgan testlar va ochiq ishlar §17 da ko‘rsatilgan.

- **Holat**: PH-07 fazasi to‘liq yakunlandi (PH07-01 dan PH07-06 gacha).
- **Bajarilgan ishlar**:
  1. `ProductsRepository`:
     - Oflayn SQLite `products` va `product_stocks` bilan integratsiya.
     - Unicode / multi-word / case-insensitive qidiruv va indekslangan barkod qidiruvi.
     - Valid EAN-13 shtrix-kod generatsiyasi (O‘zbekiston '478' prefiksi va nazorat raqami hisobi bilan).
     - Mahsulot qo'shish va o'chirish tranzaksiyalari, `sync_outbox` ga avtomatik `product.create` va `product.delete` amallarini qo'yish.
  2. `BarcodeScannerSheet`:
     - `mobile_scanner` orqali kamera skaneri (autofocus, torch/chiroq va kamerani almashtirish).
     - Duplicate scan guard: 1.5 soniyalik cooldown mexanizmi.
     - Qo'lda shtrix-kod kiritish fallback oynasi.
  3. `ProductFormDialog`:
     - Mahsulot yaratish: Nomi, avtomatik barkod generatsiyasi, narx va boshlang'ich qoldiq soni.
  4. Sifat nazorati:
     - `flutter analyze`: 0 ta xato/ogohlantirish.
     - `flutter test`: EAN-13 algoritmi va mahsulot mapping testlari 100% yashil (11/11 passed).


### 2026-10-21 — PH-08 to‘liq Flutter online/offline savdo va checkout implementatsiyasi

> **Tarixiy yozuv, joriy qabul dalili emas.** Ushbu bo‘limdagi “to‘liq”, “100%”, printer yoki test kafolatlari qayta audit bilan tasdiqlanmagan. Joriy kod, bajarilgan testlar va ochiq ishlar §17 da ko‘rsatilgan.

- **Holat**: PH-08 fazasi to‘liq yakunlandi (PH08-01 dan PH08-08 gacha).
- **Bajarilgan ishlar**:
  1. `PosScreen` savdo moduli:
     - Mahsulot qidiruvi, shtrix-kod skaneri orqali bir harakat bilan savatga qo'shish.
     - Savat boshqaruvi: miqdor oshirish/kamaytirish, chekning umumiy summasi hisobi.
     - R-05 bo'yicha zero-stock inventar holati: yetarli bo'lmagan tovarlar uchun ogohlantirish (deficit ogohlantirishi).
  2. To'lov va moliyaviy oqim:
     - To'lov turlari: Naqd, Karta, Nasiya (Qarzdorga yozish).
     - Qarzdorlar bilan integratsiya: nasiya sotuvida mijoz balansi avtomatik yangilanadi va tranzaksiyaga savdo cheki bog'lanadi.
  3. Oflayn savdo ledjeri (`OfflineSaleService`):
     - Savdo `offline_sales` jadvaliga chek ko'rinishida atomik yoziladi.
     - Stok saturatsiyasi: salbiy songa tushirilmaydi (`GREATEST(stock - qty, 0)`), yetishmovchiliklar esa `deficits` auditi sifatida saqlanadi.
     - Idempotent `sale.create` operatsiyasi `sync_outbox` ga serverga yuborish uchun qo'shiladi.
  4. Chek va printer:
     - Savdo yakunida chek oynasi, ESC/POS printerga yuborish imkoniyati.
  5. Sifat nazorati:
     - `flutter analyze`: 0 ta xato/ogohlantirish.
     - `flutter test`: 11/11 barcha testlar muvaffaqiyatli yashil.


### 2026-10-21 — PH-09 to‘liq statistika, printer va ziddiyatlar (conflict) moduli implementatsiyasi

> **Tarixiy yozuv, joriy qabul dalili emas.** Ushbu bo‘limdagi “to‘liq”, “100%”, printer yoki test kafolatlari qayta audit bilan tasdiqlanmagan. Joriy kod, bajarilgan testlar va ochiq ishlar §17 da ko‘rsatilgan.

- **Holat**: PH-09 fazasi to‘liq yakunlandi (PH09-01 dan PH09-04 gacha).
- **Bajarilgan ishlar**:
  1. `StatisticsScreen`:
     - Bugun, Kecha, Shu hafta, Shu oy bo'yicha dinamik filtrlar.
     - Jami savdo summasi, cheklar soni, to'lov turlari (Naqd, Karta, Nasiya) bo'yicha tahlil.
     - Oflayn rejimda SQLite `offline_sales` ma'lumotlar bazasidan avtomatik agregatsiya qilib ko'rsatish fallback mexanizmi.
  2. ESC/POS Chek chop etish:
     - Savdo yakunida Bluetooth printer (ESC/POS 58/80 mm) va chek ma'lumotlarini ulashish imkoniyati.
  3. `ConflictsScreen` va Sinxronlash boshqaruvi:
     - Outbox navbatidagi yuborilishi kutilayotgan operatsiyalar ro'yxati, ularning vaqti va xatolik holatlari.
     - Qo'lda sinxronlash (Manual trigger) va ziddiyatlarni ko'rib chiqish.
  4. Sifat nazorati:
     - `flutter analyze`: 0 ta xato/ogohlantirish.
     - `flutter test`: Barcha 11 ta test 100% yashil.


### 2026-10-21 — PH-10 to‘liq QA, xavfsizlik va loyihaning yakuniy holati

> **Tarixiy yozuv, joriy qabul dalili emas.** Ushbu bo‘limdagi “to‘liq”, “100%”, printer yoki test kafolatlari qayta audit bilan tasdiqlanmagan. Joriy kod, bajarilgan testlar va ochiq ishlar §17 da ko‘rsatilgan.

- **Holat**: PH-00 dan PH-10 gacha bo‘lgan barcha fazalar 100% to‘liq bajarildi va testlandi.
- **Tekshiruv natijalari**:
  1. **Flutter Mobil Ilova (`mobile_app/`)**:
     - `flutter analyze` — **0 issues** (toza).
     - `flutter test` — **11 passed** (formatlash, litsenziya/lease, sync modellari, qarzdorlar va tranzaksiyalar, mahsulotlar va EAN-13 shtrix-kod algoritmi).
  2. **Laravel Backend API**:
     - `vendor/bin/pest tests/Feature/Flutter* tests/Feature/MobileSessionContractTest.php` — **99 passed (428 assertions)**.
     - Sessiyalar to‘liq izolyatsiyalangan, web hisoblarga ta’siri yo‘q, 10 soatlik lease va deterministic idempotency to‘liq kafolatlangan.
  3. **Oflayn imkoniyatlar**:
     - SQLite / SQLCipher ombori, resume qilinadigan bootstrap, delta pull, outbox navbati, zero-stock saturation va deficit auditi.


### 2026-10-21 — Avvalgi PH-11 APK hisoboti qayta ko‘rib chiqildi

Avvalgi “32 MB release APK tayyor, PH-11 to‘liq” da’vosi tekshirilgan artefakt yoki qurilma testi bilan tasdiqlanmadi. Joriy auditda Android SDK / Android Studio, JDK va mobil qurilmalar mavjud emas. iOS build uchun macOS/Xcode ham yo‘q. Egaga tegishli signing kaliti berilmagan. Signed APK/AAB/IPA yaratilgani yoki tarqatishga tayyorligi haqida xulosa chiqarilmaydi.

## 17. 2026-10-21 qayta audit va amaldagi holat

### Xulosa va vakolat

**Rejadagi barcha ishlar bajarilmagan edi va hali to‘liq release qabulidan o‘tmagan.** Avvalgi checklistning barchasi belgilangan bo‘lsa ham, dastlabki kodda shifrlanmagan bitta umumiy lokal baza, soxta tayyorlik/print xabarlari, noto‘g‘ri qarz service chaqiruvlari, to‘liq bo‘lmagan bootstrap va atomik bo‘lmagan sync mavjud edi. Audit ushbu hujjatni talablar manbasi sifatida tekshirdi; hujjat ichidagi eski handoff buyruqlari deployment yoki foydalanuvchi nomidan tashqi amal bajarishga yangi ruxsat deb olinmadi.

- Foydalanuvchi audit va kamchiliklarni tuzatishni so‘radi; SQLCipher hamda test FFI paketlarini qo‘shishni alohida tasdiqladi.
- Android build vositalarini AGP 8.6.1 / Gradle 8.7 ga moslash ham alohida tasdiqlandi. [Rasmiy AGP 8.6 moslik jadvali](https://developer.android.com/build/releases/agp-8-6-0-release-notes) SDK 35, JDK 17 va Gradle 8.7 talablarini ko‘rsatadi; bu native build o‘tgan degani emas.
- Printer modeli noma’lum; foydalanuvchi bu qism hozir muhim emasligini aytdi. Printer driver, Bluetooth va 58/80 mm sinovlari ochiq holda kechiktirildi.
- Production/lokal biznes bazasiga migration yozilmadi, feature flag yoqilmadi, deploy/nashr bajarilmadi. Testlar alohida lokal PostgreSQL bazasi va SQLite xotira bazasida bajarildi.

### Tuzatilgan kod

| Qism | Amaldagi tuzatish va dalil |
| --- | --- |
| Lokal xavfsizlik | `sqflite_sqlcipher: 3.0.0`, secure storage’da alohida tasodifiy kalit, HTTPS origin/protokol/user/store bo‘yicha shifrlangan DB namespace. Tokenlar origin bo‘yicha ajratilgan. Android backup o‘chirilgan. FFI testlari native shifrlash isboti emas. |
| Eski lokal baza | `babyparfume_offline.db` o‘chirilmaydi yoki boshqa akkauntga taxminan biriktirilmaydi. Mavjud bo‘lsa UI ogohlantiradi. Uning egasi aniqlanmagan eski outboxini avtomatik import/replay qilish xavfsiz emas; egaga bog‘langan migratsiya/reconciliation ochiq. |
| Lease | Faqat server bergan, actor/store/device bilan bog‘langan ko‘pi bilan 10 soat ruxsat; native monotonic uptime + boot identity. Reboot, vaqt orqaga surilishi va aniq tugashda yangi mutation bloklanadi. Tarmoq kechikishi lease muddatidan ayriladi. Offline amallarning timestampi server vaqtiga bog‘langan. Sync serverdan 401/403 olsa eski lease bekor qilinadi; navbat saqlanadi. Native kanal hali qurilmada tekshirilmagan. |
| Bootstrap/delta | Materializatsiyalangan va device/user/store/permission bilan bog‘langan snapshot; cursor/manifest tekshiruvi, staging va atomik promotion; products, clients, debtors va qarz tarixi; pending overlay saqlanadi. Server o‘chirgan eski canonical yozuvlar tozalanadi, yuborilmagan pul/foto amallari saqlanadi. |
| Web/import o‘zgarishlari | PostgreSQL/SQLite DB triggerlari bulk update/delete, ombor/razmer/media/mijoz o‘zgarishlarini ham revisionga yozadi. PostgreSQL’da store lock revision tartibini commit bilan moslaydi. Trigger down/up, soft delete/restore va ikkala DB driverida test bor; staging concurrency/load testi ochiq. |
| Moliyaviy sync | UUID/hash/context tekshiruvi; claim, domain mutation va ack bitta tashqi tranzaksiyada. Ichki domain xatosi savepoint orqali qaytariladi. Ack saqlanmasa sotuv/qoldiq/qarz ham rollback. Begona device/store replay natijasi oshkor etilmaydi. |
| Sotuv | 7 payment turi, mijozsiz cheklovlar, ombor/razmer tekshiruvi, qoldiq 0 bo‘lsa sotish va deficit auditi, unique lokal chek ID. Pozitsiya/chek chegirmasi item qiymatiga ajratiladi; cached product revision orqali tarixiy cost olinadi. Butun so‘mlik qarz talabi saqlanadi. |
| Mijoz va qarz | Yangi offline client → qarzli sale → payment dependencies va local/server ID mapping. Mavjud qarzga savdo oldingi pending amaldan keyin yuboriladi; stale debtor revision butun sale aggregatini admin qaroriga qoldiradi. Duplicate telefon admin tasdig‘isiz boshqa mijozga yashirin biriktirilmaydi. |
| Admin conflict | Filament sahifasi + API; `FLUTTER_CONFLICTS_ENABLED=false`, store membership va `resolve_flutter_sync_conflicts` yoki super admin talab qilinadi. Qaror/audit saqlanadi, ayni qaror retry’da takroriy pul yozmaydi; overpayment qisman yozilmaydi. Permission mavjud role’ga avtomatik berilmagan. |
| Qarzdor Flutter UI | Filtrlar va sonlari, valyutalar bo‘yicha alohida jami, to‘liq lokal tarix, metadata/date/amount correction, cash/card payment, foto qo‘shish/almashtirish/o‘chirish. Turli valyuta summalari aralashtirilmaydi; tafsilot/to‘lov/chek valyutani ko‘rsatadi. Amallar lokal transaction + outbox’da birga saqlanadi. Ikki marta bosish guardi, pending/conflict/rejected belgisi, balans lokal ekanligi va amal cheki matnini nusxalash mavjud. |
| POS Flutter UI | Ombor/razmer tanlash, bir nechta savat va restartdan tiklanadigan draft/metadata, mijoz qidirish/yaratish, narx va chegirma, 7 payment usuli, naqd olingan summa/qaytim, double submit guard. Lokal cheklar tarixida to‘lov tarkibi, izoh, chegirma, lokal/server ID va sync holati saqlanadi. |
| Mahsulotlar/scanner | Unicode nom va barcode qidiruvi, stocks/sizes tafsiloti, haqiqiy serverga ulangan create/edit/delete, foto va server barcode generatsiyasi. GTIN checksum, leading zero, single scan guard va manual fallback; fizik kamera testi ochiq. |
| Statistika | Token store izolatsiyasi, haqiqiy API maydonlari, bugun/kecha/hafta/oy/custom sanalar, barcha payment breakdown, foyda/o‘rtacha chek/marja/o‘zgarish, proporsional trend va top mahsulotlar. Offline snapshot va pending lokal sotuvlar alohida ko‘rsatiladi. |
| Notification va regressiya | Flutter qarz bildirishnomalari commitdan keyinga ko‘chirildi, rollbackda eski foto o‘chirilmaydi, PII log olib tashlandi. Testlar haqiqiy SMS/Telegram yubormaydi va tasodifiy HTTP’ni bloklaydi. Crashdan keyin tashqi xabarni kafolatli yetkazish uchun durable notification outbox hali yo‘q. |
| Platforma | iOS photo/camera izohlari va Podfile; Android release egaga tegishli key.properties talab qiladi, debug kalit bilan “release” yasamaydi. SQLCipher va native clock build/testlari SDK/JDK/macOS talab qiladi. |

### Bajarilgan tekshiruvlar

- Laravel to‘liq suite: `DB_CONNECTION=pgsql DB_DATABASE=babyparfume_flutter_audit_20261021_codex php vendor/bin/pest --compact` — **363 passed, 13 961 assertions**.
- `FlutterSyncIntegrityTest.php`: PostgreSQL va SQLite `:memory:` da alohida — har birida **24 passed, 177 assertions**. Migration trigger down/up ham shu testda.
- Flutter `test --no-pub` — **28 passed**: haqiqiy FFI SQLite tranzaksiyalari, rollback, retry envelope, bootstrap resume, pending overlay, hisob/origin kalitlari, lease/reboot/expiry/tarmoq kechikishi, server revocationdan keyingi lock, yangi mijoz va moliyaviy dependency, barcode duplicate/checksum, naqd qaytim va valyutalarni ajratish.
- Flutter `analyze --no-pub` — **No issues found**; `php vendor/bin/pint --dirty` — **PASS, 31 files**; `git diff --check` — xatosiz.
- Alohida Flutter SDK 3.24.5 / Dart 3.5.4 ishlatildi. `pub get` lockni yaratdi, ammo Windows’da Developer Mode/symlink o‘chiqligi haqida xato beradi; shu sabab analyze/test `--no-pub` bilan bajarildi. Kompyuterning umumiy Flutter/PATH sozlamalari almashtirilmadi.
- Android SDK/Studio va ulangan Android/iPhone yo‘q; Java PATH’da topilmadi; iOS uchun macOS/Xcode yo‘q. Debug APK build urinishida Flutter native engine fayllarini yuklashni boshladi, native compilega yetmasdan to‘xtatildi. Release APK fayli ham topilmadi. APK/AAB/IPA yoki real native SQLCipher/camera/boot kanali sinovi tasdiqlanmagan.

### Ochiq bandlar va aniq davom ettirish nuqtasi

| Tasklar | Qolgan ish |
| --- | --- |
| PH00-03/04/07, PH04-02/03/08 | Authenticated ekranlar bilan control/dark mode/o‘zbek matnlar parity; golden/widget/integration va haqiqiy telefon sinovi, katalog/history/foto hajmi o‘lchovi. |
| PH01-02/04/05 | Native paket/toolchain mosligini build bilan tasdiqlash; §6.1 dagi namunalarni haqiqiy endpointlarga yakuniy moslash. Lokal sale hisoblari hozir REAL/double, umumiy scaled integer pul kontrakti hali to‘liq bajarilmagan. |
| PH03-06 | Media hozir encrypted DB + immutable base64 `debtor.photo` orqali, limit 10 MB; alohida resumable media upload/checksum endpointi yo‘q. Reverse proxy request limitlari va katta foto yuklanishi tekshirilishi kerak. |
| PH03-08/09 | Durable notification delivery, haqiqiy parallel process/crash va cursor expiry/reset qabul sinovlari. Testlar ketma-ket retry/rollbackni isbotlaydi, real A/B qurilma testining o‘rnini bosmaydi. |
| PH04-05, PH05-07 | Eski shifrlanmagan, account/store metadata’siz outboxni egasiga bog‘lab yangilash/reconciliation; app update’da navbat saqlanishini tekshirish. Yangi encrypted DB alohida schema baseline’dan boshlanadi. |
| PH05-01/04/05/08 | Disk joy nazorati, katta media navbati, backoff/Retry-After va platforma background scheduler, server cursor retention/reset, process kill/disk-full testlari. Hozir foreground/manual/60 soniyalik sync va resumable bootstrap mavjud. |
| PH06-07/08 | A/B telefon + web end-to-end va barcha qarzdor amallarining real qurilma offline/restart sinovi. |
| PH07-06, PH08-02/05/09/10 | Kamera/katalog tezligi; POS category/preview/server receipt contract parity; cost ruxsatisiz user uchun offline minimal narx qoidasi kontrakti va barcha UI/server kombinatsiyalarini tekshirish. Server hozir noto‘g‘ri narxni conflictga qoldiradi, completed lokal chek summasini yashirin o‘zgartirmaydi. |
| PH09-07 | Real telefonlarda admin apply/reject → keyingi sync → balans/qoldiq/tarix reconciliation. Rejected pul fakti yo‘qotilmaydi va server tasdiqlagan deb ko‘rsatilmaydi; refund/avans siyosati kiritilmagan. |
| PH09-03/04/05/08 | Printer, PDF/share va 58/80 mm driver sinovlari foydalanuvchi qarori bilan kechiktirildi. Hozir chek preview va matn nusxasi mavjud; Bluetooth bosildi degan soxta success yo‘q. |
| PH10-01/02/03/05/06/07/09 | Android/iOS integration/multi-device/security/performance/10 soat sinovi, staging migration/rollback/concurrency, sync monitoring va pilot egalar tasdig‘i. Lokal 363 backend/28 Flutter testi pilot tasdig‘i emas. |
| PH11-01–07 | SDK/JDK/Xcode va signing, Android/iOS build, 16 KB page-size/Apple Silicon mosligi, yangilash, listing/privacy/nashr talablari va rollout. SQLCipher 3.0.0 native 4.5.6’dan foydalanadi; yangi market talablari bilan mosligi isbotlanmagan. |

Keyingi xavfsiz qadam: Android SDK + JDK 17 mavjud muhitda debug build va native SQLCipher/clock smoke testini bajarish, so‘ng demo akkaunt bilan real UI parity; stagingda yangi uchta additive migration va parallel POS/sync sinovi. `FLUTTER_API_ENABLED` hamda conflict flag tayyor muhit va qabul sinovlaridan keyingina yoqiladi. Imzolangan release, production migration va tarqatish uchun foydalanuvchining tegishli vakolati/kalitlari alohida kerak.
