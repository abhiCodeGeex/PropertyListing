<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta http-equiv="Cache-Control" content="no-store" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Signing you in…</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif;
         display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
    .box{max-width:540px;text-align:center}
  </style>
</head>
<body>
<div class="box">
  <h1>Signing you in…</h1>
  <p>Please wait a moment.</p>
</div>

<script>
(function () {
  const payload = @json($payloadJson ?? []);
  const frontendUrl = @json(rtrim($frontendUrl ?? '', '/'));
  const FRONTEND = new URL(frontendUrl);
  const TARGET_ORIGIN = FRONTEND.origin;
  
  function toBase64Utf8(input) {
    const str = typeof input === 'string' ? input : JSON.stringify(input);
    const bytes = new TextEncoder().encode(str);
    let bin = "";
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin);
  }

  function redirectWithPayload() {
    const b64 = toBase64Utf8(payload);
    const url = `${frontendUrl}/login?oauth=${encodeURIComponent(b64)}`;
    window.location.replace(url);
  }

  function notifyOpenerThenCloseOrRedirect() {
    try {
      window.opener.postMessage({ type: "OAUTH_RESULT", payload }, TARGET_ORIGIN);
      setTimeout(function () {
        try { window.close(); } catch (_) {}
        setTimeout(redirectWithPayload, 200);
      }, 200);
    } catch (e) {
      redirectWithPayload();
    }
  }
  
  if (window.opener && !window.opener.closed) {
    notifyOpenerThenCloseOrRedirect();
  } else {
    redirectWithPayload();
  }
  
  setTimeout(redirectWithPayload, 1500);
})();
</script>
</body>
</html>
