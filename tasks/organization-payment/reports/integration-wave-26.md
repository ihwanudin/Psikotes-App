# Integration wave 26 — bukti browser P12b parsial

Tanggal: 2026-09-02

Commit worker `2e2c788`, `e28d3ee`, dan `69857b7` diintegrasikan sebagai
`71fc95a`, `fc8313f`, dan `697e210`. View memperoleh label/id native dan
perbandingan selected ID yang stabil setelah hidrasi browser. Harness hanya
loopback, memakai SQLite/storage/session/cache disposable, envDir/configFile
false, provider/notifier fake, CSP ketat, dan deny outbound.

Verifikasi Playwright dipecah menjadi tiga fase bounded setelah run monolitik
melewati 180 detik. Fresh run membuktikan 10 attempt, empat disabled reason aman,
Tab/Space/Enter native, total IDR server-authoritative, enam geometry check pada
320/390/1280, stale consultation/price fail closed, double Enter menghasilkan
satu bill canonical, reload mempertahankan bill, serta secrecy dan role/tenant/
guest denial. DB fixture selesai tepat pada 2 bill termasuk baseline claimed,
11 charge, 11 item, 2 audit, dan seluruh entitlement/outbox/order/consent/
identity side effect nol.

Root mengulang focused P12b **19 tes / 61 assertions**, PHP/Node syntax, Pint,
dan diff-check. Tidak ada route/discovery produksi, schema/toggle, layanan nyata,
DB aktif, migrasi, deploy, atau push.

Acceptance belum ditutup. Halaman detail OrganizationBill existing melebar pada
320px; harness detail belum menyediakan seluruh asset Filament/avatar lokal dan
merekam diagnostic 404/Alpine/CSP setelah redirect. Console/network bersih yang
diterima baru sampai selection/preview. Increment berikutnya memperbaiki detail,
melengkapi asset harness, dan membuktikan direct detail denial browser.
