@extends('layouts.app')

@section('title', 'About Our Marketplace | Lechon Delights')

@section('content')
<div style="max-width: 960px; margin: 0 auto; padding: 40px 20px;">
    <div style="text-align: center; margin-bottom: 40px;">
        <span style="color: #b3261e; font-weight: 800; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px;">Our Heritage & Craft</span>
        <h1 style="font-size: 2.5rem; font-weight: 900; margin: 8px 0 12px;">The Premier Lechon Marketplace in Cavite</h1>
        <p style="color: #667085; font-size: 1.1rem; line-height: 1.6;">
            Bridging master roasters, native pig suppliers, and Filipino families for every celebration.
        </p>
    </div>

    <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 20px; padding: 36px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04); margin-bottom: 32px;">
        <h2 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 16px;">🔥 Authentic Slow-Roasted Perfection</h2>
        <p style="color: #475467; line-height: 1.7; margin-bottom: 16px;">
            Lechon Delights Marketplace connects customers directly to verified local roast houses throughout Cavite. Whether you need whole lechon for a fiesta, juicy lechon belly for Sunday lunch, or crunchy spicy sisig, our system ensures fresh preparation, standard quality control, and rapid door-to-door logistics.
        </p>
        <p style="color: #475467; line-height: 1.7;">
            Each store partner undergoes rigorous food safety and authenticity validation, ensuring our customers experience golden crispy skin, aromatic lemongrass stuffing, and tender meat with every single order.
        </p>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px;">
        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 24px;">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: #fff1f0; color: #b3261e; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 14px;">
                <i class="fas fa-fire-flame-curved"></i>
            </div>
            <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 8px;">Fresh Daily Roasting</h3>
            <p style="font-size: 0.88rem; color: #667085; line-height: 1.5;">Roasts prepared with native herbs and bamboo charcoal on the day of your delivery.</p>
        </div>

        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 24px;">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: #ecfdf3; color: #027a48; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 14px;">
                <i class="fas fa-shield-check"></i>
            </div>
            <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 8px;">Verified Local Partners</h3>
            <p style="font-size: 0.88rem; color: #667085; line-height: 1.5;">All roaster partners are fully vetted with verified health compliance.</p>
        </div>

        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 24px;">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: #eff8ff; color: #175cd3; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 14px;">
                <i class="fas fa-truck-fast"></i>
            </div>
            <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 8px;">Cavite Express Tracking</h3>
            <p style="font-size: 0.88rem; color: #667085; line-height: 1.5;">Live GPS tracking and temperature-insulated delivery straight to your venue.</p>
        </div>
    </div>
</div>
@endsection
