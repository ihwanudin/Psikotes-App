<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="checkout-csrf-token" content="{{ $checkoutCsrf }}">
    <title>Checkout asesmen</title>
</head>
<body>
    <main data-checkout-session="{{ $principal->sessionPublicId }}"
          data-assessment-status="{{ $principal->assessmentStatus }}">
        <h1>Checkout asesmen</h1>
        <form method="post" action="/checkout/logout">
            <input type="hidden" name="_checkout_csrf" value="{{ $checkoutCsrf }}">
            <button type="submit">Keluar</button>
        </form>
    </main>
</body>
</html>
