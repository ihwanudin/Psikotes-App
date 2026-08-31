<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $error ? 'Tautan psikotes bermasalah' : 'Menyiapkan psikotes' }}</title>
    <style nonce="{{ $nonce }}">
        :root { color-scheme: light; font-family: ui-sans-serif, system-ui, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; background: #f8fafc; color: #0f172a; }
        main { width: min(100%, 32rem); border: 1px solid #cbd5e1; background: #fff; padding: 2rem; border-radius: 1rem; box-shadow: 0 1px 3px rgb(15 23 42 / .08); text-align: center; }
        .mark { width: 3rem; height: 3rem; margin: 0 auto; display: grid; place-items: center; border-radius: .75rem; background: #ccfbf1; color: #115e59; font-weight: 800; }
        h1 { margin: 1.25rem 0 .5rem; font-size: clamp(1.4rem, 6vw, 1.875rem); line-height: 1.2; }
        p { margin: 0; color: #475569; line-height: 1.65; }
        .spinner { width: 1.5rem; height: 1.5rem; margin: 1.5rem auto 0; border: 3px solid #99f6e4; border-top-color: #0f766e; border-radius: 999px; animation: spin .8s linear infinite; }
        .error { background: #fef2f2; color: #991b1b; }
        a { display: inline-flex; margin-top: 1.5rem; min-height: 2.75rem; align-items: center; justify-content: center; border-radius: .625rem; background: #0f766e; padding: .75rem 1rem; color: white; font-weight: 700; text-decoration: none; }
        a:focus-visible { outline: 3px solid #5eead4; outline-offset: 3px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; border-top-color: #99f6e4; } }
    </style>
</head>
<body>
    <main aria-live="polite">
        @if ($error)
            <div class="mark error" aria-hidden="true">!</div>
            <h1>Tautan tidak dapat diproses</h1>
            <p>{{ $error }}</p>
            <a href="/">Kembali ke halaman utama</a>
        @else
            <div class="mark" aria-hidden="true">✓</div>
            <h1>Menyiapkan ruang psikotes</h1>
            <p>Identitas Anda berhasil diverifikasi. Mohon tunggu sebentar.</p>
            <div class="spinner" role="status" aria-label="Memuat ruang psikotes"></div>
            <script nonce="{{ $nonce }}">
                const participantToken = @json($participantToken);
                sessionStorage.setItem('participant_access_token', participantToken);
                window.history.replaceState(null, '', @json($cleanPath ?? '/participant/lobby'));
                window.location.replace('/participant/lobby');
            </script>
        @endif
    </main>
</body>
</html>
