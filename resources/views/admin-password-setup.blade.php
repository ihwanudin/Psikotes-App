<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Atur kata sandi admin</title>
    <style nonce="{{ $nonce }}">
        :root { color-scheme: light; font-family: ui-sans-serif, system-ui, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; background: #f8fafc; color: #0f172a; }
        main { width: min(100%, 26rem); border: 1px solid #cbd5e1; background: #fff; padding: 2rem; border-radius: 1rem; }
        h1 { margin: 0 0 .5rem; font-size: 1.5rem; }
        p { margin: 0 0 1.25rem; color: #475569; line-height: 1.6; }
        label { display: block; font-size: .875rem; font-weight: 600; margin-bottom: .25rem; }
        input { width: 100%; padding: .6rem .75rem; border: 1px solid #cbd5e1; border-radius: .5rem; margin-bottom: 1rem; font-size: 1rem; }
        button { width: 100%; padding: .7rem; border: none; border-radius: .5rem; background: #0f172a; color: #fff; font-weight: 600; font-size: 1rem; cursor: pointer; }
        button:disabled { opacity: .6; cursor: not-allowed; }
        #feedback { margin-top: 1rem; font-size: .875rem; }
        #feedback.error { color: #991b1b; }
        #feedback.success { color: #115e59; }
    </style>
</head>
<body>
    <main>
        <h1 id="title">Atur kata sandi</h1>
        <p id="subtitle">Kata sandi minimal 12 karakter, kombinasi huruf besar/kecil, angka, dan simbol.</p>
        <form id="form">
            <label for="password">Kata sandi baru</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required minlength="12">
            <label for="password_confirmation">Ulangi kata sandi</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required minlength="12">
            <button type="submit" id="submit">Simpan kata sandi</button>
        </form>
        <p id="feedback" role="status" aria-live="polite"></p>
    </main>
    <script nonce="{{ $nonce }}">
        const fragment = new URLSearchParams(window.location.hash.slice(1));
        const token = fragment.get('token');
        window.history.replaceState(null, '', window.location.pathname);

        const feedback = document.getElementById('feedback');
        const form = document.getElementById('form');
        const submit = document.getElementById('submit');

        const show = (message, kind) => {
            feedback.textContent = message;
            feedback.className = kind;
        };

        if (!token) {
            form.remove();
            show('Token tidak ditemukan pada tautan. Minta tautan baru kepada operator.', 'error');
        } else {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                submit.disabled = true;
                const password = document.getElementById('password').value;
                const passwordConfirmation = document.getElementById('password_confirmation').value;

                fetch(@json(route('admin.password-setup.consume', ['publicId' => $publicId])), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ token, password, password_confirmation: passwordConfirmation }),
                }).then(async (response) => {
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Kata sandi tidak dapat disimpan.');
                    form.remove();
                    show('Kata sandi berhasil diatur. Anda dapat masuk melalui halaman admin.', 'success');
                }).catch((error) => {
                    submit.disabled = false;
                    show(error.message, 'error');
                });
            });
        }
    </script>
</body>
</html>
