@extends('layouts.app')

@section('title', 'Frequently Asked Questions | Lechon Delights')

@section('content')
<div style="max-width: 860px; margin: 0 auto; padding: 40px 20px;">
    <div style="text-align: center; margin-bottom: 36px;">
        <h1 style="font-size: 2.2rem; font-weight: 900; margin-bottom: 8px;">Frequently Asked Questions</h1>
        <p style="color: #667085; font-size: 1rem;">Answers to common questions regarding ordering, delivery, and payments.</p>
    </div>

    <div style="display: flex; flex-direction: column; gap: 16px;">
        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 14px; padding: 22px;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #101828; margin-bottom: 8px;">What areas in Cavite do you deliver to?</h3>
            <p style="color: #475467; font-size: 0.92rem; line-height: 1.6;">We deliver across all major Cavite cities including General Trias, Dasmariñas, Imus, Bacoor, Tagaytay, Silang, Tanza, Kawit, and Rosario via our partner roasters and express motorcycle/MPV couriers.</p>
        </div>

        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 14px; padding: 22px;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #101828; margin-bottom: 8px;">How long does lechon preparation and delivery take?</h3>
            <p style="color: #475467; font-size: 0.92rem; line-height: 1.6;">Standard meal items (Lechon belly, sisig, rice bowls) take 25–45 minutes. Whole lechons ordered for same-day delivery require 2–3 hours roasting time. For peak weekend dates, we recommend booking advance pre-orders 24–48 hours ahead.</p>
        </div>

        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 14px; padding: 22px;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #101828; margin-bottom: 8px;">What payment methods are supported?</h3>
            <p style="color: #475467; font-size: 0.92rem; line-height: 1.6;">We accept Cash on Delivery (COD), GCash, Maya, and Visa/Mastercard online payments powered securely by PayMongo.</p>
        </div>

        <div style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 14px; padding: 22px;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #101828; margin-bottom: 8px;">Can I pick up my order directly from the branch?</h3>
            <p style="color: #475467; font-size: 0.92rem; line-height: 1.6;">Yes! At checkout, simply select "Store Pick-up". You will receive notification when your roast is freshly chopped and packed for pickup with zero delivery fees.</p>
        </div>
    </div>
</div>
@endsection
