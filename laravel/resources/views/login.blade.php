<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in | Cloud ERP</title><link rel="icon" href="/mais-logo.png">
    <style>
    *{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#17283e;font:16px Arial,sans-serif;display:grid;min-height:100vh;place-items:center;padding:24px}.card{width:100%;max-width:420px;background:white;border:1px solid #dce4ed;border-radius:16px;padding:32px}img{display:block;margin:0 auto;width:105px;height:105px;object-fit:contain}h1{font-size:26px;margin-bottom:8px}p{color:#637085;line-height:1.6}label{display:block;margin:20px 0;font-size:14px}input{display:block;width:100%;margin-top:8px;padding:12px;border:1px solid #cbd5e1;border-radius:7px;font:inherit}button{cursor:pointer;border:0;border-radius:8px;padding:13px;width:100%;font-size:16px;background:#1756bd;color:white}.local{background:#e8effa;color:#1756bd;margin-top:16px}small{display:block;text-align:center;color:#637085;margin-top:24px}.error{background:#fff0f1;padding:12px;color:#a32d3e;border-radius:8px}
    </style>
</head><body><main class="card">
<img src="/mais-logo.png" alt="Mais company logo"><h1>Welcome to Cloud ERP</h1><p>Sign in to your corporate gifting workspace.</p>
@if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
<form method="post" action="/login">@csrf
<label>Email<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required></label>
<label>Password<input name="password" type="password" autocomplete="current-password" required></label>
<button type="submit">Sign in</button></form>
@if(app()->environment('local') && in_array(request()->ip(), ['127.0.0.1', '::1']) && in_array(request()->getHost(), ['127.0.0.1', 'localhost', '[::1]']))
<form method="post" action="/local-preview">@csrf<button class="local">Open local preview</button></form>
@endif
<small>Developed by Irfan Dossani</small>
</main></body></html>
