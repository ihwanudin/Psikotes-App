<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Menyiapkan psikotes</title>
    <style nonce="{{ $nonce }}">
        :root { color-scheme: light; font-family: ui-sans-serif, system-ui, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; background: #f8fafc; color: #0f172a; }
        main { width: min(100%, 32rem); border: 1px solid #cbd5e1; background: #fff; padding: 2rem; border-radius: 1rem; text-align: center; }
        .mark { width: 3rem; height: 3rem; margin: 0 auto; display: grid; place-items: center; border-radius: .75rem; background: #ccfbf1; color: #115e59; font-weight: 800; }
        h1 { margin: 1.25rem 0 .5rem; font-size: 1.75rem; }
        p { margin: 0; color: #475569; line-height: 1.65; }
        .error { background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>
    <main aria-live="polite">
        <div id="mark" class="mark" aria-hidden="true">✓</div>
        <h1 id="title">Menyiapkan ruang psikotes</h1>
        <p id="message">Mohon tunggu sebentar.</p>
    </main>
    <script nonce="{{ $nonce }}">
        const fragment = new URLSearchParams(window.location.hash.slice(1));
        const token = fragment.get('token');
        window.history.replaceState(null, '', window.location.pathname);

        const reject = (message) => {
            document.getElementById('mark').classList.add('error');
            document.getElementById('mark').textContent = '!';
            document.getElementById('title').textContent = 'Tautan tidak dapat diproses';
            document.getElementById('message').textContent = message;
        };

        if (!token) {
            reject('Token undangan tidak ditemukan. Minta tautan baru kepada organisasi Anda.');
        } else {
            fetch(@json(route('assessment.invitations.consume', ['publicId' => $publicId])), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ token }),
            }).then(async (response) => {
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Tautan tidak dapat diproses.');
                sessionStorage.setItem('participant_access_token', payload.participantToken);
                window.location.replace('/participant/lobby');
            }).catch((error) => reject(error.message));
        }
    </script>
</body>
</html>
