# OsonPOS Print Agent — o‘rnatish va tekshirish

Laravel/cPanel internet orqali topshiriqlarni saqlaydi. Flutter chek yoki barcode PDFni oldindan ko‘rsatadi va topshiriq yuboradi. Do‘kon kompyuteridagi C# agent PDFni tekshiradi, Windowsning PDF rendererida tayyorlaydi va admin tanlagan printerga dialogsiz uzatadi. .NET dastur **do‘kon kompyuterida** ishlaydi; cPanelga .NET runtime, VPS yoki doimiy queue worker kerak emas.

Bu V1: har do‘konga bitta faol kompyuter, internet orqali ulanish. Kompyuter va telefon bir Wi‑Fi tarmog‘ida bo‘lishi shart emas. Agent yoqilgan, Windows foydalanuvchisi tizimga kirgan va printer drayveri shu foydalanuvchiga ko‘rinadigan bo‘lishi kerak.

## Talablar va fayllar

- Windows 11 22H2 / build 22621 yoki yangiroq, x64.
- Windowsga o‘rnatilgan mos chek/etiketka printer drayveri. Model nomi dasturga yozilmagan; admin Windows queue nomini ro‘yxatdan tanlaydi. Har bir model, drayver va materialda fizik tekshiruv zarur.
- Agent: `.NET 10`, WinForms, `win-x64`, self-contained. Do‘kon owneriga .NET yoki SDK o‘rnatish kerak emas.
- Installer: `print_agent/artifacts/OsonPOSPrintAgentSetup.exe`, versiya `1.0.1`. Bu fayl Gitga kiritilmaydi, release bilan alohida tarqatiladi. Authenticode imzosi hozircha yo‘q.
- Renderer: Windowsning `Windows.Data.Pdf` API; alohida PDF dasturi yoki uchinchi tomon PDF renderer paketi talab qilinmaydi.
- Flutter: rozilik bilan qo‘shilgan `printing: 5.13.4`, ilova ichidagi PDF preview uchun. Haqiqiy chop etish Windows agent orqali bajariladi.

## Serverni chiqarishga tayyorlash

Quyidagi buyruqlar deploy yo‘riqnomasi; implementatsiya paytida prod bazada bajarilmagan.

1. Odatdagi release/backup jarayoni bilan Laravel kodini, vendor va yig‘ilgan `public/build` fayllarini joylang. `print_agent` binarlari serverda ishga tushirilmaydi.
2. To‘g‘ri release va baza tanlanganini tekshirib, migratsiya va faqat print ruxsatlari seederini bajaring:

   ```sh
   php artisan migrate --force
   php artisan db:seed --class=PrintingPermissionSeeder --force
   ```

3. Admin paneldagi rollarga kerakli ruxsatlarni bering. Seeder rolga avtomatik ruxsat qo‘shmaydi:

   | Ruxsat | Vazifa |
   | --- | --- |
   | `manage_printing` | Kompyuter ulash/bekor qilish, profillar va avtomatik chek |
   | `print_receipts` | Chek chiqarish |
   | `print_barcodes` | Mahsulot etiketkasi va bulk chiqarish |
   | `reprint_receipts` | Chekni sabab bilan qayta chiqarish |
   | `view_print_jobs` | Do‘konning boshqa kassirlari topshiriqlarini ko‘rish |
   | `test_printing` | Adminning moliyaviy yozuv yaratmaydigan test hujjati |

   Foydalanuvchi tegishli do‘kon a’zosi bo‘lishi ham shart. `super_admin` ruxsatlardan o‘tadi, do‘kon chegarasi saqlanadi.

4. Migratsiyadan keyin `.env`ga `PRINTING_ENABLED=true` qo‘ying. Amaldagi deploy jarayoni bilan config/permission cachelarni yangilang. Dastlabki holat `false`; avtomatik chek ham har do‘konda dastlab o‘chiq.
5. `storage/app/private` va `storage/fonts` PHP uchun yoziladigan bo‘lsin. Print PDFlar private local diskda saqlanadi, public symlink orqali berilmaydi. DomPDF uchun mavjud `public/fonts/DejaVuSans.ttf` kerak.
6. Mavjud Laravel scheduler cron ishlasin. `printing:prune` har kuni 03:30, Asia/Tashkent vaqtida ishlaydi. Navbat uchun queue worker yoki scheduler talab qilinmaydi; scheduler eski hujjatlarni tozalash uchun kerak.

APIlar: agent uchun `/api/print-agent/v1`, Flutter uchun mavjud `/api/flutter/v1/stores/{store}/printing` va `print-jobs` yo‘llari. HTTPS majburiy; agentda faqat lokal ishlab chiqish uchun loopback HTTPga ruxsat bor. Agent serveriga API endpointni emas, ilovaning asosiy HTTPS manzilini kiriting. Redirect va TLS tekshiruvini o‘chirish qo‘llanmaydi.

## Owner kompyuteriga o‘rnatish

1. Printerning to‘g‘ri Windows drayverini o‘rnating. Windowsdagi queue nomi, porti, kerakli qog‘oz/formasi va sensor rejimini tekshiring.
2. `OsonPOSPrintAgentSetup.exe`ni oching, startup katagini tanlang va **O‘rnatish**ni bosing. Dastur joriy Windows foydalanuvchisiga o‘rnatiladi; administrator huquqi agentning o‘zi uchun talab qilinmaydi.
3. Laravel admin panelida kerakli do‘konni tanlab, **Sozlamalar → Chop etish** sahifasidan bir martalik 12 belgili ulash kodini oling. Kod 10 daqiqada tugaydi.
4. Agent oynasida server HTTPS manzili, kompyuter nomi va kodni kiriting. **Kompyuterni ulash**ni bosing. Token serverda hash, kompyuterda Windows DPAPI orqali saqlanadi.
5. Agent trayda qoladi. Oynani X bilan yopish agentni to‘xtatmaydi; traydagi **Chiqish** to‘xtatadi. **Diagnostika** printerlar va oxirgi lokal topshiriqlarni ko‘rsatadi.
6. Admin sahifasini yangilang. Printerlarni profillarga biriktiring va printerda hozir yuklangan qog‘oz turini `receipt` yoki `barcode` qilib belgilang. `unknown` yoki boshqa turdagi qog‘ozga topshiriq yuborilmaydi.
7. Tayyor profillar: chek 58/80 mm, etiketka 30×20 va 57×30 mm. Admin yangi o‘lchamni millimetrda, margin, nusxa limiti va barcode turini qo‘sha oladi. Windows drayveri shu o‘lchamni qabul qilishi kerak.
8. Har hujjat turiga bitta standart profil belgilang. Kassir tanlay oladigan profillarda tegishli katakni yoqing. Avtomatik chek uchun standart chek profili va printer kerak; uni admin alohida yoqadi.
9. **Test chiqarish** bilan demo hujjat yuboring. Bu savdo, mahsulot, qoldiq yoki qarz yozuvi yaratmaydi. Barcode skan bilan aynan o‘sha qiymatga qaytishini, boshidagi nollarni, matnni, orientatsiya va qog‘oz uzilishini tekshiring.

Bitta qurilma chek va etiketka rejimlarini qo‘llasa ham, fizik qog‘oz va drayver rejimi o‘zgarishi kerak. Admin media belgisini ham moslashtiradi. Ikki alohida printer ishlatilsa, har biri o‘z profiliga biriktiriladi. Virtual PDF/XPS/OneNote printerlari avtomatik yo‘lga ruxsat etilmaydi.

### 1.0.1: chek lentasi va bosiladigan en

XP-80C kabi drayverlar 80 mm lentani `80(72.1)` shaklida qaytaradi: lenta 80 mm, bosiladigan joy taxminan 72 mm. Agent chek uchun drayverdagi shu nominal o‘lchamga mos formani oladi va barcha kontentni bosiladigan joyga mutanosib joylaydi. Admin profilidagi 80 mm nominal qiymat saqlanadi. Drayver qaytargan eski fizik balandlikdan tashqari haqiqiy bosiladigan balandlik ham tekshiriladi; e’tiborsiz qoldirilgan custom uzunlik qabul qilinmaydi. Barcode etiketkasida avtomatik kichraytirish qo‘llanmaydi va o‘lcham qat’iy tekshiriladi.

`paper_size_unsupported`ning avvalgi XP-80C holati real drayverda qayta hosil qilindi. Tuzatilgan yo‘l 80×160 mm chekni tayyorladi, shuningdek lokal `OSONPOS TEST 1.0.1` demo Windows navbatiga bir marta yuborildi; server yoki biznes bazasiga murojaat qilinmadi. Windows navbatiga qabul fizik bosma/sifat isboti sifatida ko‘rsatilmaydi.

Yangi agentni o‘rnatishda avval traydan **Chiqish**, so‘ng setupni ustiga o‘rnating. Pairing va jurnal saqlanadi. Muddati tugagan eski topshiriq o‘rniga admindan yangi **Test hujjati chiqarish** yuboring. Bu tuzatish uchun Flutter APK yoki Laravel migratsiyasini yangilash talab qilinmaydi.

## Flutterdagi ish tartibi

- Sotuv natijasi yoki **Cheklar**dan chekni oching. Qo‘lda chop etish oynalarida **Oldindan ko‘rish** va **Print** yonma-yon turadi: PDF preview ixtiyoriy, **Print** hujjatni tayyorlab darhol navbatga yuboradi.
- Mahsulot sahifasida barcode chiqarish bor. Mahsulotlar ro‘yxatida tanlab, bir nechta mahsulot uchun har biriga alohida etiketka soni kiritiladi.
- Ruxsatlar serverdan keladi. Backend flag/ruxsatlar yoqilmaguncha yangi tugmalar ko‘rinmaydi. Server va Flutter buildi birga yangilanishi kerak.
- Admin yoqqan avtomatik chek savdo saqlangach printer/o‘lcham tanlash oynasisiz yuboriladi. Server joriy standart chek profilidan printer, o‘lcham va nusxalar sonini oladi; kassir qurilmasidagi eski profil tanlovi marshrutlashni o‘zgartirmaydi. Print xatosi saqlangan savdoni qayta yaratmaydi. Offline savdoning print intenti lokal saqlanadi; internet qaytganida eski cheklar ommaviy avtomatik yuborilmaydi. Kassir **Cheklar**dan qayta tekshiradi/tasdiqlaydi. Qo‘lda va avtomatik yuborish bir vaqtda boshlanganida shu chek uchun mavjud intent qayta ishlatiladi.
- **Chop etish tarixi** so‘nggi topshiriqlar, holat, bekor qilish va ruxsat bo‘lsa sabab bilan qayta chiqarishni beradi. Qayta chiqarilgan chekda **NUSXA** yoziladi.

## Navbat, xatolar va ma’lumotlar

- Original tasdiq vaqtidan 600 soniya davomida hali Windowsga yuborilmagan topshiriq kutiladi. Vaqt tugasa yangi tasdiq talab qilinadi; retry vaqtni yangilamaydi.
- `spooled` — **Printer navbatiga yuborildi**. Bu qog‘ozga chiqqanini isbotlamaydi. Windows navbatiga allaqachon qabul qilingan topshiriq printer tiklanganda kechroq chiqishi mumkin.
- `unknown` — chiqish natijasi noma’lum yoki qisman chiqish ehtimoli bor. Printer va Windows navbatini tekshirib, keyin yangi tasdiq/sabab bilan qayta chiqaring. Agent bunday topshiriqni avtomatik takrorlamaydi.
- Agent javob yo‘qolishi va restartda disk jurnalidan ayni eventni tiklaydi. Job UUID va PDF SHA-256 tekshiriladi; server print oldidan profil/ruxsat/TTLni qayta tekshiradi.
- `profile_stale`: admin profilni o‘zgartirgan; sozlamalarni yangilab qayta tasdiqlang. `media_mismatch`: yuklangan qog‘oz turi mos emas. `printer_not_found`: queue yo‘qolgan yoki nom/driver/port almashgan. `paper_size_unsupported`: drayverdagi formani tekshiring.
- Dastur: `%LOCALAPPDATA%\Programs\OsonPOSPrintAgent\OsonPOSPrintAgent.exe`.
- Sozlamalar/token: `%LOCALAPPDATA%\OsonPOS\PrintAgent\settings.json`. Tokenni yoki bu faylni yordam uchun yubormang.
- Durable jurnal: `%LOCALAPPDATA%\OsonPOS\PrintAgent\journal\{agent-uuid}\{job-uuid}.json`. Har job atomic replace va disk flush bilan saqlanadi; SQLite dependency qo‘shilmadi. Turli agent/do‘kon jurnallari ajratilgan.
- Lokal PDF/PNG cache: shu ildizdagi `cache\{agent-uuid}\{job-uuid}`. Muvaffaqiyatli uzatilgan fayllar 7 kundan keyin tozalanadi; pending/unknown va jurnal saqlanadi.
- Serverda tugagan PDFlar 7 kundan, tugagan audit metadata 90 kundan keyin tozalanadi. Pending/unknown avtomatik o‘chirilmaydi. Qayta print mavjud payload snapshotidan yangi PDF tayyorlaydi.

## Yig‘ish, yangilash va qaytarish

Developer kompyuterida .NET 10 SDK bilan:

```powershell
./print_agent/build.ps1
# Maxsus SDK yo‘li bo‘lsa:
./print_agent/build.ps1 -Dotnet 'C:/path/to/dotnet.exe'
```

Natija: `artifacts/app/OsonPOSPrintAgent.exe`, `artifacts/OsonPOSPrintAgentSetup.exe` va `.exe.sha256` checksum fayli. EXEni ownerga uzatishdan oldin release versiyasi va checksumni birga saqlang.

Yangilashda traydan **Chiqish**, so‘ng yangi setup. Lokal sozlamalar va jurnal o‘chirilmaydi. Oldingi versiyaga qaytarishda ham shu tartib; navbat noma’lum bo‘lsa avval audit qiling. Windows Installed apps orqali uninstall startup va EXEni olib tashlaydi, jurnal/sozlamalarni saqlaydi. Uninstall yordamchisining vaqtinchalik EXEsi Tempda qolishi mumkin.

Server funksiyasini vaqtincha to‘xtatish uchun `PRINTING_ENABLED=false` va odatdagi config cache yangilanishi yetarli. Kompyuterni almashtirishda admin eski agentni bekor qiladi, eskisining Windows navbatini tekshiradi va keyin yangi kod beradi. Migratsiyani rollback qilib print auditini o‘chirish oddiy o‘chirish yo‘li emas.

## Avtomatik tekshiruv va qolgan qabul

2026-10-25 holatida:

| Tekshiruv | Dalil |
| --- | --- |
| Laravel print, receipt metadata, Flutter sync va sale/debt regressiyasi | Alohida PostgreSQL `test`: 56 test o‘tdi; o‘zgargan nusxa limitini qayta tekshirish ham qo‘shilgan |
| Flutter barcha testlari | Mock API / in-memory SQLite: 156 test o‘tdi |
| Dart statik tekshiruv | `flutter analyze` xatosiz |
| Agent crash/replay/TTL/hash/ruxsat testi va haqiqiy PDFni native render qilish | 9 avtomatik test, fizik printer ishlatilmadi |
| Frontend | `npm run build` muvaffaqiyatli |
| Android | Debug APK yig‘ildi: `mobile_app/build/app/outputs/flutter-apk/app-debug.apk`; prodga ulangan app ochilmadi |
| Published agent | Self-contained EXE 30×20 mm barcode va 58 mm chek PDFlarini Windowsda muvaffaqiyatli rasterladi; kirill va qalin shrift ko‘rib tekshirildi |

Agent testlari:

```powershell
dotnet run --project print_agent/tests/OsonPos.PrintAgent.Tests
# O‘rnatilgan drayver bilan tekshiruv, qog‘oz chiqarmaydi:
dotnet run --project print_agent/tests/OsonPos.PrintAgent.Tests -- --printer XP-80C
# Faqat ataylab tanlanganda bitta lokal demo chiqaradi, server/baza ishlatilmaydi:
dotnet run --project print_agent/tests/OsonPos.PrintAgent.Tests -- --print-demo XP-80C
# Lokal demo PDF bilan qo‘shimcha renderer testi:
dotnet run --project print_agent/tests/OsonPos.PrintAgent.Tests -- --pdf C:/path/to/two-label-30x20.pdf
# Published EXEni printerni ishlatmasdan tekshirish:
./print_agent/artifacts/app/OsonPOSPrintAgent.exe --check-pdf C:/path/to/demo.pdf C:/path/to/report.json
# Published agentda drayver/o‘lcham diagnostikasi, qog‘oz chiqarmaydi:
./print_agent/artifacts/app/OsonPOSPrintAgent.exe --check-printer XP-80C receipt 80 160 C:/path/to/printer.json
```

PHP testlarida `DB_DATABASE=test`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_URL=''`ni alohida o‘rnating. `tests/Feature/PrintingApiTest.php`, `PrintingReceiptMetadataTest.php`, `FlutterSyncIntegrityTest.php`, `SaleDebtTest.php`ni ishlating. Flutter testini `--dart-define=API_BASE_URL=https://one.test/api/flutter/v1` bilan bajaring. Prod baza yoki prodga ulangan app test manbasi bo‘lmaydi.

XP365 to‘liq modeli/driveri va haqiqiy qog‘ozda barcode skan, chek printeri, cutter/gap/black mark, installer/startup/update/uninstallning owner sessiyasidagi qabul sinovi hamda iOS build/UI hali tekshirilmagan. Production deploy va real pairing bajarilmagan. Apparat qabul dalili `PRINT_AGENT_TZ.md` AC-03/05/07/18 bo‘yicha alohida yoziladi.
