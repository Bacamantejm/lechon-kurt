<?php
if (!function_exists('legalPoliciesLastUpdatedLabel')) {
    function legalPoliciesLastUpdatedLabel(): string
    {
        return 'April 17, 2026';
    }
}

if (!function_exists('renderTermsOfServiceSections')) {
    function renderTermsOfServiceSections(): void
    {
        ?>
        <h2>1. Acceptance of Terms</h2>
        <p>By creating an account, placing an order, or using this marketplace, you agree to these Terms of Service and all applicable store policies shown at checkout and in your order details.</p>

        <h2>2. Marketplace Role</h2>
        <p>Lechon Delights is a marketplace platform connecting customers and partner food businesses. Product quality, preparation, and fulfillment are primarily handled by the selected partner store.</p>

        <h2>3. Orders, Pricing, and Availability</h2>
        <ul>
            <li>All listings, prices, and availability may change without prior notice.</li>
            <li>Orders are confirmed only after successful payment authorization and order acceptance.</li>
            <li>Stores may decline or cancel orders due to stock limits, safety concerns, delivery constraints, or pricing errors.</li>
        </ul>

        <h2>4. Food Safety and Allergen Notice</h2>
        <ul>
            <li>Customers must review ingredients and allergy information before ordering.</li>
            <li>Cross-contact may occur in shared kitchens; allergen-free preparation cannot always be guaranteed.</li>
            <li>Food should be consumed promptly and stored or reheated using safe food-handling practices.</li>
        </ul>

        <h2>5. Delivery and Pickup</h2>
        <ul>
            <li>Delivery and pickup windows are estimates and may vary due to traffic, weather, rider availability, or operational events.</li>
            <li>Customers must provide accurate contact and address details. Failed deliveries due to incorrect information may result in additional charges.</li>
            <li>For pickup, orders should be claimed within the agreed schedule window to maintain product quality.</li>
        </ul>

        <h2>6. Payments and Downpayment Policy</h2>
        <ul>
            <li>Supported payment types include full payment and, when offered, downpayment for selected orders.</li>
            <li>For downpayment orders, the remaining balance must be settled based on the checkout terms.</li>
            <li><strong>Downpayment is non-refundable when the customer cancels the order.</strong></li>
            <li>Any eligible refund, if approved, excludes non-refundable downpayment amounts.</li>
        </ul>

        <h2>7. Cancellation and Refund Rules</h2>
        <ul>
            <li>Cancellation eligibility depends on the order status and store policy shown in your order page.</li>
            <li>Refund requests may require supporting evidence (for example, product damage photos).</li>
            <li>Approved refund amounts are based on platform/store policy checks and payment records.</li>
            <li>Chargeback abuse, fraudulent claims, or policy misuse may lead to account restrictions.</li>
        </ul>

        <h2>8. Business Partner Subscription Plans & Cancellation Policy</h2>
        <ul>
            <li><strong>Non-Refundable Policy:</strong> All subscription fee payments (monthly and annual terms) are strictly non-refundable once invoiced or charged. No cash refunds, chargeback reimbursements, or prorated partial refunds will be issued upon subscription cancellation or downgrade.</li>
            <li><strong>Full Access Retained Through End of Paid Term:</strong> Upon cancellation of a subscription, the partner shop will continue to have complete, uninterrupted access to all features, commission discounts, staff limits, and capabilities of their chosen plan until the end of their current active paid billing period (e.g. 1 month from the billing/renewal date).</li>
            <li><strong>Automatic Concluding of Services:</strong> Once the 1-month paid period reaches its expiration date, recurring charges will stop completely and subscription tier benefits will conclude without penalty.</li>
        </ul>

        <h2>9. User Responsibilities</h2>
        <ul>
            <li>Provide accurate account, payment, and delivery information.</li>
            <li>Do not misuse promos, payment channels, support tools, or dispute flows.</li>
            <li>Keep login credentials confidential and report unauthorized access immediately.</li>
        </ul>

        <h2>10. Limitation of Liability</h2>
        <p>To the maximum extent allowed by law, Lechon Delights is not liable for indirect, incidental, or consequential losses arising from service interruptions, delays, partner-store actions, or third-party gateway failures.</p>

        <h2>11. Changes to Terms</h2>
        <p>We may update these terms from time to time. Continued use of the service after updates means you accept the revised terms.</p>

        <h2>12. Contact</h2>
        <p>
            Lechon Delights<br>
            orders@lechondelights.com<br>
            8939-1221 / 8851-2987
        </p>
        <?php
    }
}

if (!function_exists('renderPrivacyPolicySections')) {
    function renderPrivacyPolicySections(): void
    {
        ?>
        <h2>1. Information We Collect</h2>
        <ul>
            <li>Account data such as full name, email, phone number, and encrypted password.</li>
            <li>Order and delivery information such as items, address, and transaction references.</li>
            <li>Operational data such as device/browser details, session logs, and IP address.</li>
            <li>Support and messaging data when you contact stores or platform support.</li>
        </ul>

        <h2>2. Why We Use Your Data</h2>
        <ul>
            <li>To create and secure your account.</li>
            <li>To process, fulfill, and support your orders and refunds.</li>
            <li>To provide delivery coordination, store communication, and customer support.</li>
            <li>To prevent fraud, abuse, and security incidents.</li>
            <li>To improve service quality, reliability, and marketplace operations.</li>
        </ul>

        <h2>3. Sharing of Data</h2>
        <p>We may share relevant data with partner stores, delivery providers, payment gateways, and service vendors only to operate and secure transactions. We do not sell your personal data.</p>

        <h2>4. Data Retention</h2>
        <p>We keep records only as long as needed for operations, legal obligations, fraud prevention, dispute handling, and financial reporting.</p>

        <h2>5. Security Measures</h2>
        <p>We use reasonable administrative and technical safeguards, but no system is completely risk-free. Users must also protect their account credentials.</p>

        <h2>6. Your Privacy Choices</h2>
        <ul>
            <li>You may request profile updates and corrections through your account.</li>
            <li>You may request account review or deletion subject to legal and transaction-retention requirements.</li>
            <li>You may disable non-essential communications where available.</li>
        </ul>

        <h2>7. Children and Sensitive Data</h2>
        <p>This service is not intended for unauthorized use by minors. Do not submit sensitive personal information unless required for lawful transaction processing.</p>

        <h2>8. Policy Updates</h2>
        <p>We may revise this Privacy Policy when operational, legal, or security requirements change. Updated versions are effective upon posting.</p>

        <h2>9. Contact</h2>
        <p>
            Lechon Delights<br>
            orders@lechondelights.com<br>
            8939-1221 / 8851-2987
        </p>
        <?php
    }
}
