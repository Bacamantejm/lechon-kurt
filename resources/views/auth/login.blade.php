<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | Lechon Delights Marketplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #ffffff; color: #101828; }
        .auth-container { display: flex; min-height: 100vh; }
        .auth-left { width: 45%; background: linear-gradient(135deg, #b3261e 0%, #8f261a 100%); color: #ffffff; padding: 60px 48px; display: flex; flex-direction: column; justify-content: space-between; position: relative; }
        .auth-right { width: 55%; padding: 60px 48px; display: flex; flex-direction: column; justify-content: center; max-width: 540px; margin: 0 auto; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 0.88rem; font-weight: 700; color: #344054; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 13px 16px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.95rem; outline: none; font-family: inherit; }
        .form-control:focus { border-color: #b3261e; box-shadow: 0 0 0 4px rgba(179, 38, 30, 0.12); }
        .btn-submit { width: 100%; padding: 14px; background: #b3261e; color: #ffffff; border: none; border-radius: 10px; font-size: 1rem; font-weight: 800; cursor: pointer; transition: background 0.2s; }
        .btn-submit:hover { background: #981b15; }
        @media (max-width: 900px) {
            .auth-container { flex-direction: column; }
            .auth-left { width: 100%; padding: 36px 24px; }
            .auth-right { width: 100%; padding: 36px 24px; }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-left">
            <div style="display: flex; align-items: center; gap: 10px; font-size: 1.3rem; font-weight: 900; font-family: 'Outfit', sans-serif;">
                <i class="fas fa-piggy-bank"></i> Lechon Delights
            </div>
            <div>
                <h1 style="font-family: 'Outfit', sans-serif; font-size: 3rem; font-weight: 900; line-height: 1.15; margin-bottom: 16px;">
                    Cavite's Finest Lechon at Your Doorsteps
                </h1>
                <p style="font-size: 1.1rem; color: rgba(255,255,255,0.9); line-height: 1.5;">
                    Sign in to manage your orders, access partner roaster menus, and track delivery in real time.
                </p>
            </div>
            <div style="font-size: 0.85rem; color: rgba(255,255,255,0.75);">
                &copy; {{ date('Y') }} Lechon Delights Marketplace.
            </div>
        </div>

        <div class="auth-right">
            <h2 style="font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 900; margin-bottom: 8px;">Welcome Back</h2>
            <p style="color: #667085; font-size: 0.95rem; margin-bottom: 28px;">Enter your email address and password to sign into your account.</p>

            @if($errors->any())
                <div style="background: #fff1f0; border: 1px solid #fee4e2; color: #b3261e; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 600;">
                    <i class="fas fa-circle-exclamation" style="margin-right: 6px;"></i> {{ $errors->first() }}
                </div>
            @endif

            <form action="{{ route('login.post') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" value="{{ old('email') }}" required placeholder="name@example.com" class="form-control">
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <label class="form-label" style="margin-bottom: 0;">Password</label>
                    </div>
                    <input type="password" name="password" required placeholder="Enter your account password" class="form-control">
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; font-size: 0.9rem;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #475467; font-weight: 600;">
                        <input type="checkbox" name="remember" style="accent-color: #b3261e;"> Remember me
                    </label>
                    <a href="{{ route('home') }}" style="color: #b3261e; font-weight: 700;">Back to Home</a>
                </div>

                <button type="submit" class="btn-submit">Sign In to Account</button>
            </form>

            <div style="text-align: center; margin-top: 28px; font-size: 0.92rem; color: #667085;">
                Don't have an account yet? 
                <a href="{{ route('register') }}" style="color: #b3261e; font-weight: 800;">Create an account</a>
            </div>
        </div>
    </div>
</body>
</html>
